#!/usr/bin/env python3
"""
Checks an X account for visibility restrictions, the way shadowban.yuzurisa.com does,
from a separate probe account's session (never the watched account's own session).

Usage:  xcheck.py <handle> --cookies <probe cookies.txt> [--timeout 40]
Output: one JSON object on stdout:
  {"ok": true, "probe": "...", "profile": {...}, "search": {...}, "typeahead": {...},
   "ghost": {...}, "deboost": {...}}
Each test is {"ban": true|false|null, ...}; null means the test could not be run.
Reads the rendered page (DOM), so it does not depend on X's private API formats.
"""
import argparse
import json
import random
import re
import sys
import urllib.parse

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36")
# headless Chromium announces itself in sec-ch-ua and X answers 403
CH_UA = '"Chromium";v="153", "Google Chrome";v="153", "Not_A Brand";v="8"'
STATUS = re.compile(r"^/([A-Za-z0-9_]{1,15})/status/(\d+)")

# X uses the probe account's own UI language, so match English and Korean
HIDDEN_REPLIES = re.compile(r"show (probable spam|more replies|additional replies)|스팸|답글 더 보기|추가 답글", re.I)
# empty search page that blames the viewer's "hide sensitive content" setting
SENSITIVE_HINT = re.compile(r"sensitive content|민감한 콘텐츠", re.I)


def load_cookies(path: str) -> list[dict]:
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
                    if int(expires) > 0:
                        c["expires"] = int(expires)
                except ValueError:
                    pass
                out.append(c)
    except OSError:
        return []
    return out


# Reads every rendered post: author handle, status id, time, and whether X labels it a reply.
ARTICLES_JS = """() => [...document.querySelectorAll('article[data-testid="tweet"]')].map(a => {
  const t = a.querySelector('time');
  const link = t ? t.closest('a') : null;
  const head = (a.innerText || '').slice(0, 300);
  return { href: link ? link.getAttribute('href') : null,
           time: t ? t.getAttribute('datetime') : null,
           reply: /Replying to|님에게 보내는 답글/.test(head) };
})"""


def articles(page) -> list[dict]:
    out = []
    try:
        rows = page.evaluate(ARTICLES_JS)
    except Exception:  # noqa: BLE001
        return out
    for r in rows:
        m = STATUS.match(r.get("href") or "")
        if m:
            out.append({"handle": m.group(1), "id": m.group(2), "time": r.get("time"), "reply": r.get("reply")})
    return out


def pause(page, lo=1200, hi=2200):
    page.wait_for_timeout(random.randint(lo, hi))


def wait_timeline(page, timeout_ms: int) -> None:
    """Waits until posts or an empty/error state is rendered."""
    try:
        page.wait_for_selector('article[data-testid="tweet"], [data-testid="emptyState"], '
                               '[data-testid="error-detail"]', timeout=timeout_ms)
    except Exception:  # noqa: BLE001
        pass
    pause(page, 1500, 2500)


def body_text(page) -> str:
    try:
        return page.evaluate("() => document.body.innerText || ''")
    except Exception:  # noqa: BLE001
        return ""


def run(handle: str, cookies: str, timeout: int) -> dict:
    from playwright.sync_api import sync_playwright

    me = handle.lower()
    res: dict = {"ok": False, "handle": handle, "probe": None,
                 "profile": {}, "search": {"ban": None}, "typeahead": {"ban": None},
                 "ghost": {"ban": None}, "deboost": {"ban": None}}
    jar = load_cookies(cookies)
    if not jar:
        res["error"] = "probe cookies missing"
        return res

    with sync_playwright() as pw:
        browser = pw.chromium.launch(args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu",
                                           "--disable-blink-features=AutomationControlled"])
        ctx = browser.new_context(user_agent=UA, locale="en-US", timezone_id="Asia/Seoul",
                                  viewport={"width": 1280, "height": 1800},
                                  extra_http_headers={"sec-ch-ua": CH_UA})
        ctx.add_cookies(jar)
        page = ctx.new_page()
        ms = timeout * 1000
        try:
            # 1. profile, and who the probe is
            page.goto(f"https://x.com/{handle}", wait_until="domcontentloaded", timeout=ms)
            wait_timeline(page, ms)
            probe = page.evaluate("""() => { const a = document.querySelector('[data-testid="AppTabBar_Profile_Link"]');
                                             return a ? a.getAttribute('href').replace(/^\\//, '') : null; }""")
            res["probe"] = probe
            if not probe:
                res["error"] = "probe not logged in (cookies expired?)"
                return res
            if probe.lower() == me:
                res["error"] = "probe account is the watched account; register a different account"
                return res
            text = body_text(page)
            posts = [a for a in articles(page) if a["handle"].lower() == me]
            prof = {"exists": True, "suspended": False, "protected": False, "has_tweets": bool(posts)}
            if re.search(r"This account doesn.t exist|계정이 존재하지 않", text):
                prof["exists"] = False
            elif re.search(r"Account suspended|계정이 정지|계정 정지", text):
                prof["suspended"] = True
            elif re.search(r"These posts are protected|게시물은 비공개|비공개 게시물", text):
                prof["protected"] = True
            m = re.search(r"([\d.,]+[KM만천]?) (?:posts|게시물)", text)
            if m:
                prof["posts"] = m.group(1)
                prof["has_tweets"] = prof["has_tweets"] or m.group(1) not in ("0",)
            res["profile"] = prof
            if not prof["exists"] or prof["suspended"] or prof["protected"]:
                res["ok"] = True
                return res

            # 2. search ban: from:handle on the Latest tab
            pause(page)
            q = urllib.parse.quote(f"from:{handle}")
            page.goto(f"https://x.com/search?q={q}&src=typed_query&f=live", wait_until="domcontentloaded", timeout=ms)
            wait_timeline(page, ms)
            found = [a for a in articles(page) if a["handle"].lower() == me]
            res["search"] = {"ban": (not found) if prof["has_tweets"] else None, "count": len(found)}
            if not found and SENSITIVE_HINT.search(body_text(page)):
                # the probe hides sensitive content, so an empty result says nothing about a ban
                res["search"] = {"ban": None, "count": 0, "note": "probe_sensitive_filter"}

            # 3. search suggestion ban: does @handle come up in the search box typeahead?
            try:
                box = page.locator('[data-testid="SearchBox_Search_Input"]').first
                box.click(timeout=8000)
                box.fill("")
                box.type(f"@{handle}", delay=90)
                page.wait_for_timeout(3500)
                hits = page.evaluate("""() => [...document.querySelectorAll('[data-testid="typeaheadResult"]')]
                                              .map(e => e.innerText || '')""")
                ok = any(re.search(r"@" + re.escape(handle) + r"\b", h, re.I) for h in hits)
                res["typeahead"] = {"ban": not ok, "results": len(hits)}
            except Exception as e:  # noqa: BLE001
                res["typeahead"] = {"ban": None, "error": type(e).__name__}

            # 4. ghost ban / reply deboosting: find a recent reply, then look for it under its parent
            pause(page)
            q = urllib.parse.quote(f"from:{handle} filter:replies")
            page.goto(f"https://x.com/search?q={q}&src=typed_query&f=live", wait_until="domcontentloaded", timeout=ms)
            wait_timeline(page, ms)
            replies = [a for a in articles(page) if a["handle"].lower() == me]
            if not replies:
                res["ghost"] = {"ban": None, "stage": "no reply found"}
                res["deboost"] = {"ban": None, "stage": "no reply found"}
                res["ok"] = True
                return res
            reply = replies[0]
            pause(page)
            page.goto(f"https://x.com/{handle}/status/{reply['id']}", wait_until="domcontentloaded", timeout=ms)
            wait_timeline(page, ms)
            thread = articles(page)
            parent = None
            for i, a in enumerate(thread):
                if a["id"] == reply["id"] and i > 0:
                    parent = thread[i - 1]
                    break
            if not parent:
                res["ghost"] = {"ban": None, "stage": "parent not found", "reply": reply["id"]}
                res["deboost"] = {"ban": None, "stage": "parent not found", "reply": reply["id"]}
                res["ok"] = True
                return res

            pause(page)
            page.goto(f"https://x.com/{parent['handle']}/status/{parent['id']}", wait_until="domcontentloaded", timeout=ms)
            wait_timeline(page, ms)
            seen = False
            for _ in range(8):
                if any(a["id"] == reply["id"] for a in articles(page)):
                    seen = True
                    break
                page.mouse.wheel(0, 1400)
                page.wait_for_timeout(1300)
            info = {"reply": reply["id"], "parent": parent["id"], "parent_handle": parent["handle"]}
            if seen:
                res["ghost"] = {"ban": False, **info}
                res["deboost"] = {"ban": False, **info}
            else:
                # replies X ranks low sit behind "Show probable spam" / "Show more replies"
                opened = 0
                for _ in range(3):
                    btn = page.get_by_role("button", name=HIDDEN_REPLIES)
                    if btn.count() == 0:
                        break
                    try:
                        btn.first.click(timeout=5000)
                        opened += 1
                        page.wait_for_timeout(2500)
                    except Exception:  # noqa: BLE001
                        break
                    if any(a["id"] == reply["id"] for a in articles(page)):
                        seen = True
                        break
                if seen:
                    res["ghost"] = {"ban": False, **info}
                    res["deboost"] = {"ban": True, **info}
                else:
                    many = len(articles(page)) >= 40
                    # in a very long thread the reply may simply be further down
                    res["ghost"] = {"ban": None if many else True, "uncertain": many, **info}
                    res["deboost"] = {"ban": None, **info}
            res["ok"] = True
            return res
        except Exception as e:  # noqa: BLE001
            res["error"] = f"{type(e).__name__}: {str(e)[:200]}"
            return res
        finally:
            browser.close()


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("handle")
    ap.add_argument("--cookies", required=True)
    ap.add_argument("--timeout", type=int, default=40)
    a = ap.parse_args()
    if not re.match(r"^[A-Za-z0-9_]{1,15}$", a.handle):
        print(json.dumps({"ok": False, "error": "bad handle"}))
        return
    print(json.dumps(run(a.handle, a.cookies, a.timeout), ensure_ascii=False))


if __name__ == "__main__":
    main()
    sys.exit(0)
