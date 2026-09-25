#!/usr/bin/env python3
"""
Renders a social post in headless Chromium and reports the media it exposes.

Used as a fallback for platforms whose pages are JavaScript-only (Threads,
Instagram), where a plain HTTP fetch returns an empty shell.

Usage:  render_media.py <url> [--timeout 45]
Output: one JSON object on stdout:
  {"ok": true, "title": "...", "author": "...", "videos": [...], "images": [...], "text": "..."}
  {"ok": false, "error": "..."}
Media URLs are returned as-is; the caller must still validate the host.
"""
import base64
import json
import re
import sys
import urllib.parse

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36")
MEDIA_HOST = re.compile(r"(cdninstagram|fbcdn)\.(com|net)", re.I)
IMG_EXT = re.compile(r"\.(jpe?g|png|webp|heic|gif)(\?|$)", re.I)
VID_EXT = re.compile(r"\.(mp4|m4v|webm|mov)(\?|$)", re.I)
VID_PATH = re.compile(r"video_dashinit|bytestart|/o1/v/|\.mp4", re.I)
JUNK_IMG = re.compile(r"rsrc\.php|/static/|\.svg(\?|$)|/s\d{2}x\d{2}/", re.I)
# Profile pictures live under the -19 CDN buckets and are served at tiny sizes.
PROFILE_IMG = re.compile(r"/t51\.[0-9.]+-19/|_s(?:[1-9]\d|1\d\d|2[0-4]\d)x(?:[1-9]\d|1\d\d|2[0-4]\d)", re.I)


def load_cookies(path: str) -> list[dict]:
    """Reads a Netscape cookies.txt file into Playwright cookie dicts."""
    out = []
    try:
        with open(path, encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                f = line.split("\t")
                if len(f) < 7:
                    continue
                domain, _flag, cpath, secure, expires, name, value = f[:7]
                c = {"name": name, "value": value, "domain": domain, "path": cpath or "/",
                     "secure": secure.upper() == "TRUE", "httpOnly": False, "sameSite": "Lax"}
                try:
                    exp = int(expires)
                    if exp > 0:
                        c["expires"] = exp
                except ValueError:
                    pass
                out.append(c)
    except OSError:
        return []
    return out


HANDLE = re.compile(r"^[A-Za-z0-9._]{2,30}$")
AGE = re.compile(r"^\d+\s*(초|분|시간|일|주|개월|년|[smhdw])$")
COUNT = re.compile(r"^[\d][\d.,]*\s*(천|만|억|K|M|B)?$", re.I)
UI_LINES = {"번역하기", "답글 보기", "더 보기", "좋아요", "답글", "리포스트", "공유",
            "팔로우", "팔로잉", "Translate", "Follow", "Following", "•"}


def parse_post(text: str) -> dict:
    """Splits a rendered Threads post into author, age, body and the four counters."""
    lines = [l.strip() for l in (text or "").split("\n")]
    lines = [l for l in lines if l and l not in UI_LINES]
    out: dict = {"author": None, "age": None, "body": "", "counts": []}
    if lines and HANDLE.match(lines[0]):
        out["author"] = lines.pop(0)
    if lines and AGE.match(lines[0]):
        out["age"] = lines.pop(0)
    # Threads prints like / reply / repost / share under the post, in that order
    counts = []
    while lines and COUNT.match(lines[-1]) and len(counts) < 4:
        counts.insert(0, lines.pop())
    out["counts"] = counts
    # an embedded post leaves its author's handle on its own line at the end; never take the
    # last remaining line, which is the body of a one-word post
    if len(lines) > 1 and HANDLE.match(lines[-1]):
        lines.pop()
    out["body"] = "\n".join(lines).strip()
    return out


CROPPED = re.compile(r"(stp=c|_s\d{2,4}x\d{2,4})", re.I)


def efg_of(url: str) -> dict:
    """Meta's CDN packs a JSON blob into the efg parameter; it says what the file really is."""
    m = re.search(r"[?&]efg=([^&]+)", url)
    if not m:
        return {}
    raw = urllib.parse.unquote(m.group(1))
    try:
        return json.loads(base64.b64decode(raw + "=" * (-len(raw) % 4)).decode("utf-8", "replace"))
    except Exception:
        return {}


def dedupe_videos(urls: list[str]) -> list[str]:
    """
    One entry per clip. A carousel hands out every DASH rendition of the same video
    (q30..q90 plus an audio-only track); they all share one xpv_asset_id, so keep the
    highest bitrate of each asset and drop the audio-only ones.
    """
    best: dict[str, tuple[int, str]] = {}
    order: list[str] = []
    for u in urls:
        e = efg_of(u)
        tag = str(e.get("vencode_tag") or "")
        if "_audio" in tag:
            continue
        key = str(e.get("xpv_asset_id") or u.split("?")[0])
        rate = int(e.get("bitrate") or 0)
        if key not in best:
            best[key] = (rate, u)
            order.append(key)
        elif rate > best[key][0]:
            best[key] = (rate, u)
    return [best[k][1] for k in order]


# Facebook embeds a progressive mp4 (video + audio in one file) next to the DASH manifest.
PROGRESSIVE = re.compile(r'"(browser_native_hd_url|playable_url_quality_hd|browser_native_sd_url|playable_url)":("(?:[^"\\]|\\.)*")')


def progressive_urls(html: str) -> dict[str, str]:
    """xpv_asset_id -> progressive mp4 URL, HD preferred over SD."""
    rank = {"browser_native_hd_url": 0, "playable_url_quality_hd": 0, "browser_native_sd_url": 1, "playable_url": 1}
    best: dict[str, tuple[int, str]] = {}
    for m in PROGRESSIVE.finditer(html or ""):
        try:
            u = json.loads(m.group(2))
        except ValueError:
            continue
        if not u or not MEDIA_HOST.search(u):
            continue
        key = str(efg_of(u).get("xpv_asset_id") or u.split("?")[0])
        r = rank[m.group(1)]
        if key not in best or r < best[key][0]:
            best[key] = (r, u)
    return {k: v[1] for k, v in best.items()}


def whole_file(url: str) -> str:
    """A DASH segment request carries bytestart/byteend; without them the CDN returns the whole file."""
    return re.sub(r"&(bytestart|byteend)=\d+", "", url)


def is_video_cover(url: str) -> bool:
    """A carousel's video slide also exposes its cover frame as a still image."""
    e = efg_of(url)
    tag = str(e.get("vencode_tag") or e.get("efg_tag") or "")
    return "cover_frame" in tag or "best_image_urlgen" in tag


def dedupe_media(urls: list[str]) -> list[str]:
    """One entry per media file: the CDN serves the same photo under several crop variants."""
    best: dict[str, str] = {}
    order: list[str] = []
    for u in urls:
        key = u.split("?")[0].rsplit("/", 1)[-1]
        if key not in best:
            best[key] = u
            order.append(key)
        elif CROPPED.search(best[key]) and not CROPPED.search(u):
            best[key] = u  # prefer the uncropped variant
    return [best[k] for k in order]


def run(url: str, timeout: float, cookies: str = "") -> dict:
    from playwright.sync_api import sync_playwright

    videos: list[str] = []
    images: list[str] = []

    def note(u: str) -> None:
        """Classify by file extension first; path hints only decide extension-less URLs."""
        if not u or u.startswith("blob:") or not MEDIA_HOST.search(u):
            return
        if IMG_EXT.search(u):
            if not JUNK_IMG.search(u) and not PROFILE_IMG.search(u) and u not in images:
                images.append(u)
            return
        if VID_EXT.search(u) or VID_PATH.search(u):
            if u not in videos:
                videos.append(u)
            return
        if not JUNK_IMG.search(u) and not PROFILE_IMG.search(u) and u not in images:
            images.append(u)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(args=[
            "--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu",
            "--autoplay-policy=no-user-gesture-required",
        ])
        ctx = browser.new_context(
            user_agent=UA, locale="ko-KR", viewport={"width": 1280, "height": 1600},
            ignore_https_errors=True,
        )
        if cookies:
            jar = load_cookies(cookies)
            if jar:
                try:
                    ctx.add_cookies(jar)
                except Exception:  # noqa: BLE001
                    pass
        page = ctx.new_page()
        page.on("response", lambda r: note(r.url))
        seen_urls: list[str] = []
        page.on("framenavigated", lambda f: seen_urls.append(f.url) if f is page.main_frame else None)
        landed = ""
        try:
            page.goto(url, wait_until="domcontentloaded", timeout=timeout * 1000)
            landed = page.url  # the app strips ?injected_media_ids a moment later
        except Exception as e:  # noqa: BLE001
            browser.close()
            return {"ok": False, "error": f"page load failed: {type(e).__name__}"}

        page.wait_for_timeout(4000)

        # Logged in, Threads answers a post permalink with the feed and the post injected on
        # top (?injected_media_ids=...). Without that marker a bare feed URL means the post
        # was not viewable, and reporting the feed's media would be plainly wrong.
        want = re.search(r"/(?:post|p|reel)/([A-Za-z0-9_-]+)", url)
        # where we actually ended up: the landing URL, the frame navigations, the current URL
        trail = " ".join(seen_urls + [landed, page.url])
        injected = "injected_media_ids" in trail
        if want and not injected and want.group(1) not in trail:
            landed = page.url
            body = ""
            try:
                body = page.evaluate("() => (document.body.innerText || '').slice(0, 800)")
            except Exception:  # noqa: BLE001
                pass
            browser.close()
            return {"ok": False, "error": "redirected", "landed": landed, "text": body,
                    "title": None, "description": None, "videos": [], "images": [], "links": []}

        try:
            page.mouse.wheel(0, 600)
            page.wait_for_timeout(1500)
        except Exception:  # noqa: BLE001
            pass

        # a paused <video> often has no real src until playback starts
        try:
            page.evaluate("""() => {
                document.querySelectorAll('video').forEach(v => {
                    try { v.muted = true; const p = v.play(); if (p && p.catch) p.catch(() => {}); } catch (e) {}
                });
            }""")
            page.wait_for_timeout(3500)
        except Exception:  # noqa: BLE001
            pass

        # In injected-feed mode only the first post is ours, so read that subtree alone and
        # drop everything the network listener picked up for the rest of the feed.
        net_first = videos[:1]  # the post's own media loads before the rest of the page
        net_img_first = images[:1]

        # A post page also renders neighbours (carousel of other posts, "more from" grid), so
        # read only the post's own subtree whenever the URL points at one.
        scope = "injected" if injected else ("post" if want else "")
        if scope:
            videos.clear()
            images.clear()
        collector = """(scope) => {
            const root = scope === 'injected'
                ? (document.querySelector('[data-pressable-container]')?.closest('div[class]')?.parentElement
                   || document.querySelector('[data-pressable-container]') || document)
                : (scope === 'post'
                    ? (document.querySelector('main article') || document.querySelector('article')
                       || document.querySelector('main') || document)
                    : document);
            const scoped = scope !== '';
            const meta = (p) => { const m = document.querySelector(`meta[property="${p}"], meta[name="${p}"]`); return m ? m.content : null; };
            const vids = [];
            root.querySelectorAll('video').forEach(v => {
                [v.currentSrc, v.src, ...[...v.querySelectorAll('source')].map(s => s.src)].forEach(s => { if (s) vids.push(s); });
                if (v.poster) vids.push('POSTER:' + v.poster);
            });
            const url = (i) => i.currentSrc || i.src;
            let imgs;
            if (scope === 'post') {
                // A post page also lists suggestions and comment avatars. The post's own frames
                // are the carousel list when there is one, else the largest images on the page.
                const big = [...root.querySelectorAll('img')].filter(i => i.naturalWidth >= 300);
                const lists = [...root.querySelectorAll('ul')]
                    .map(ul => big.filter(i => ul.contains(i)))
                    .filter(g => g.length > 0)
                    .sort((a, b) => b.length - a.length);
                let pick = lists[0] || [];
                if (pick.length === 0 && big.length) {
                    const area = (i) => i.naturalWidth * i.naturalHeight;
                    const max = Math.max(...big.map(area));
                    pick = big.filter(i => area(i) >= max * 0.4);
                }
                imgs = pick.map(url);
            } else {
                imgs = [...root.querySelectorAll('img')].filter(i => i.naturalWidth >= 200).map(url);
            }
            return {
                title: meta('og:title') || document.title || null,
                desc: meta('og:description') || null,
                ogvideo: meta('og:video') || meta('og:video:url') || meta('og:video:secure_url') || null,
                ogimage: meta('og:image') || null,
                vids, imgs,
                bodyText: ((scoped ? root.innerText : document.body.innerText) || '').slice(0, 1200),
                links: [...new Set([...root.querySelectorAll('a[href*="/post/"], a[href*="/p/"], a[href*="/reel/"]')].map(a => a.href))].slice(0, 20),
            };
        }"""
        data = page.evaluate(collector, scope)

        # A carousel renders one slide at a time, so step through it and merge what each shows.
        if scope == "post":
            for _ in range(12):
                try:
                    nxt = page.query_selector(
                        'button[aria-label="다음"], button[aria-label="Next"], '
                        '[aria-label="다음"] button, [aria-label="Next"] button')
                    if not nxt or not nxt.is_visible():
                        break
                    nxt.click(timeout=2000)
                except Exception:  # noqa: BLE001
                    break
                page.wait_for_timeout(900)
                try:
                    more = page.evaluate(collector, scope)
                except Exception:  # noqa: BLE001
                    break
                for k in ("imgs", "vids"):
                    for u in more.get(k) or []:
                        if u not in (data.get(k) or []):
                            data.setdefault(k, []).append(u)

        html = ""
        try:
            html = page.content()
        except Exception:  # noqa: BLE001
            pass
        browser.close()

    posters = []
    for v in data.get("vids") or []:
        if v.startswith("POSTER:"):
            posters.append(v[7:])
        else:
            note(v)
    for u in (data.get("ogvideo"),):
        if u:
            note(u)
    for u in (data.get("imgs") or []) + posters + [data.get("ogimage")]:
        if u:
            note(u)

    # a feed <video> often plays from a blob: URL, so fall back to the first network hit
    if scope and not videos:
        videos.extend(net_first)
    if scope and not images:
        images.extend(net_img_first)

    videos[:] = dedupe_videos(videos)
    # the player fetches DASH segments (video-only, a few hundred bytes at a time); swap each
    # for the progressive file of the same asset, else for the whole representation
    prog = progressive_urls(html)
    for k, u in enumerate(videos):
        key = str(efg_of(u).get("xpv_asset_id") or "")
        videos[k] = prog.get(key) or whole_file(u)
    if not videos and len(prog) == 1:
        videos.extend(prog.values())
    images[:] = dedupe_media(images)
    if videos:
        # the cover of a video slide is not a photo of its own; a reel has nothing but
        # the cover, so it is only dropped when real photos remain
        real = [u for u in images if not is_video_cover(u)]
        if real:
            images[:] = real

    return {
        "links": data.get("links") or [],
        "ok": bool(videos or images),
        "title": data.get("title"),
        # og:title/og:description describe the site, not the injected post; the scoped text is the post
        "description": None if scope else data.get("desc"),
        "text": data.get("bodyText"),
        "post": parse_post(data.get("bodyText") or "") if scope else None,
        "videos": videos[:10],
        "images": images[:20],
    }


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "usage: render_media.py <url>"}))
        return 2
    url = sys.argv[1]
    timeout = 45.0
    if "--timeout" in sys.argv:
        try:
            timeout = float(sys.argv[sys.argv.index("--timeout") + 1])
        except (ValueError, IndexError):
            pass
    cookies = ""
    if "--cookies" in sys.argv:
        try:
            cookies = sys.argv[sys.argv.index("--cookies") + 1]
        except IndexError:
            pass
    if not re.match(r"^https://", url):
        print(json.dumps({"ok": False, "error": "https url required"}))
        return 2
    try:
        out = run(url, timeout, cookies)
    except Exception as e:  # noqa: BLE001
        out = {"ok": False, "error": f"{type(e).__name__}: {e}"[:300]}
    print(json.dumps(out, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
