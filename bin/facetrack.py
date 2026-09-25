#!/usr/bin/env python3
"""
Keeps one chosen face in the middle of the frame: a moving crop window follows it.

The face is picked by a click on one frame. Every frame is scanned with YuNet, each
face gets an SFace identity vector, and the track follows the faces that match the
picked one (forwards and backwards from the click), so other people in the shot are
ignored. Gaps are bridged from the neighbouring positions, the path is smoothed, and
each frame is re-rendered through the window.

Usage:
  facetrack.py --in IN --out OUT --x 320 --y 540 --frame 12 \
               [--aspect 9:16] [--zoom 1.5] [--smooth 50] [--edge clamp|blur]

Prints "progress <0..100>" to stderr and a JSON summary to stdout.
Exit 4: no face at the clicked point.
"""
import argparse
import json
import math
import os
import subprocess
import sys

import cv2
import numpy as np

ASPECTS = {"9:16": 9 / 16, "4:5": 4 / 5, "1:1": 1.0, "16:9": 16 / 9}
MAX_FACES = 6        # per frame, largest first
MATCH = 0.36         # SFace cosine threshold for "same person"
WEAK = 0.22          # accepted only when the face is also where the track was


def probe(path: str) -> dict:
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries",
         "stream=width,height,r_frame_rate,nb_frames", "-show_entries", "format=duration",
         "-of", "json", path], capture_output=True, text=True, check=True).stdout
    j = json.loads(out)
    st = j["streams"][0]
    num, den = (st["r_frame_rate"].split("/") + ["1"])[:2]
    fps = float(num) / float(den or 1)
    dur = float(j.get("format", {}).get("duration") or 0)
    frames = int(st.get("nb_frames") or 0) or int(round(dur * fps))
    return {"w": int(st["width"]), "h": int(st["height"]), "fps": fps, "frames": max(1, frames)}


def even(v: float) -> int:
    return max(2, int(round(v / 2)) * 2)


def decoder(src: str, w: int, h: int):
    args = ["ffmpeg", "-v", "error", "-i", src]
    if w or h:
        args += ["-vf", f"scale={w}:{h}"]
    args += ["-f", "rawvideo", "-pix_fmt", "bgr24", "-"]
    return subprocess.Popen(args, stdout=subprocess.PIPE)


def frames(proc, w: int, h: int):
    size = w * h * 3
    while True:
        buf = proc.stdout.read(size)
        if not buf or len(buf) < size:
            return
        yield np.frombuffer(buf, np.uint8).reshape(h, w, 3)


def progress(p: float) -> None:
    print(f"progress {int(max(0, min(100, p)))}", file=sys.stderr, flush=True)


def torso_hist(img, f):
    """Colour of the clothes under a face: tells dancers apart when the face turns away."""
    x, y, w, h = f[:4]
    x0, x1 = int(x - w * 0.6), int(x + w * 1.6)
    y0, y1 = int(y + h * 1.3), int(y + h * 3.3)
    H, W = img.shape[:2]
    x0, x1, y0, y1 = max(0, x0), min(W, x1), max(0, y0), min(H, y1)
    if x1 - x0 < 4 or y1 - y0 < 4:
        return None
    hsv = cv2.cvtColor(img[y0:y1, x0:x1], cv2.COLOR_BGR2HSV)
    hist = cv2.calcHist([hsv], [0, 1], None, [18, 16], [0, 180, 0, 256])
    return cv2.normalize(hist, None, 1, 0, cv2.NORM_L1)


def analyse(src: str, v: dict, models: str):
    """Faces of every frame: list of (cx, cy, size, feature, clothes) in source pixels."""
    # features are read from a frame no larger than 1280 on the long edge, detection runs at 640
    s1 = min(1.0, 1280 / max(v["w"], v["h"]))
    aw, ah = even(v["w"] * s1), even(v["h"] * s1)
    s2 = min(1.0, 640 / max(aw, ah))
    dw, dh = max(8, int(aw * s2)), max(8, int(ah * s2))
    det = cv2.FaceDetectorYN.create(os.path.join(models, "face_detection_yunet_2023mar.onnx"),
                                    "", (dw, dh), 0.6, 0.3, 5000)
    rec = cv2.FaceRecognizerSF.create(os.path.join(models, "face_recognition_sface_2021dec.onnx"), "")
    back = v["w"] / aw   # analysis px -> source px

    out = []
    proc = decoder(src, aw, ah)
    for i, img in enumerate(frames(proc, aw, ah)):
        small = cv2.resize(img, (dw, dh), interpolation=cv2.INTER_AREA) if s2 < 1 else img
        _, faces = det.detect(small)
        row = []
        if faces is not None:
            faces = sorted(faces, key=lambda f: -f[2] * f[3])[:MAX_FACES]
            for f in faces:
                f = f.copy()
                f[:14] /= s2          # back to the analysis frame for alignment
                if min(f[2], f[3]) < 12:
                    continue
                feat = rec.feature(rec.alignCrop(img, f)).flatten()
                feat /= (np.linalg.norm(feat) + 1e-9)
                # anchor on the eyes: steadier than the box, which grows and shrinks with hair and angle
                row.append((float((f[4] + f[6]) / 2 * back), float((f[5] + f[7]) / 2 * back),
                            float(max(f[2], f[3]) * back), feat, torso_hist(img, f)))
        out.append(row)
        if i % 10 == 0:
            progress(45 * i / v["frames"])
    proc.wait()
    return out


def pick_start(faces, f0: int, x: float, y: float, diag: float):
    """The face under (or nearest to) the click, looking a few frames either side."""
    for d in (0, 1, -1, 2, -2, 3, -3, 5, -5, 8, -8):
        f = f0 + d
        if not 0 <= f < len(faces):
            continue
        best = None
        for k, (cx, cy, sz, _, _) in enumerate(faces[f]):
            dist = math.hypot(cx - x, cy - y)
            if dist <= sz * 0.9 or dist <= diag * 0.06:
                if best is None or dist < best[0]:
                    best = (dist, k)
        if best:
            return f, best[1]
    return None


def cloth_sim(h, ref) -> float | None:
    if h is None or ref is None:
        return None
    return 1.0 - float(cv2.compareHist(h, ref, cv2.HISTCMP_BHATTACHARYYA))


def follow(faces, f0: int, k0: int, diag: float):
    """
    Per-frame (cx, cy, size) of the chosen face, None where it was not seen.

    Where the face was last seen matters most; identity (face vector + clothes colour)
    decides between faces close by and allows a jump only when it is clearly the person.
    """
    n = len(faces)
    track = [None] * n
    cx, cy, sz, feat, hist = faces[f0][k0]
    track[f0] = (cx, cy, sz)
    for step in (1, -1):
        gallery, cref = [feat], hist
        prev, gap = (cx, cy, sz), 0
        f = f0 + step
        while 0 <= f < n:
            gap += 1
            cands = []
            for (x, y, s, ft, h) in faces[f]:
                fs = max(float(g @ ft) for g in gallery)
                cs = cloth_sim(h, cref)
                ident = fs if cs is None else 0.5 * fs + 0.5 * cs
                dist = math.hypot(x - prev[0], y - prev[1]) / diag
                reach = min(0.3, 0.05 + 0.012 * gap) + 0.5 * prev[2] / diag
                cands.append((ident, dist, dist <= reach, x, y, s, ft, h, fs))
            pick = None
            if cands:
                cands.sort(key=lambda c: -c[0])
                near = [c for c in cands if c[2]]
                far = [c for c in cands if not c[2]]
                bn = max(near, key=lambda c: c[0] - 0.5 * c[1]) if near else None
                bf = far[0] if far else None
                # after a long absence the face nearby may be someone else: ask for more
                floor = 0.28 if gap <= 6 else 0.4
                if bn and bn[0] >= floor and not (bf and bf[0] > bn[0] + 0.15 and bf[0] >= 0.5):
                    pick = bn
                elif bf and bf[0] >= 0.5 and (len(cands) == 1 or bf[0] >= cands[1][0] + 0.1 or cands[1] is bf):
                    pick = bf
            if pick:
                ident, _, _, x, y, s, ft, h, fs = pick
                track[f] = (x, y, s)
                prev, gap = (x, y, s), 0
                others = [c[0] for c in cands if c is not pick]
                clear = not others or ident >= max(others) + 0.1
                # learn new angles and lighting only from unambiguous frames
                if clear and ident >= 0.45:
                    if fs >= 0.45 and len(gallery) < 40 and f % 4 == 0:
                        gallery.append(ft)
                    if h is not None and cref is not None:
                        cref = cv2.normalize(cref * 0.9 + h * 0.1, None, 1, 0, cv2.NORM_L1)
            f += step
    return track


def fill(track):
    """Linear bridge over gaps, hold at both ends."""
    n = len(track)
    known = [i for i, t in enumerate(track) if t]
    xs = np.array([track[i][0] for i in known])
    ys = np.array([track[i][1] for i in known])
    idx = np.arange(n)
    return np.interp(idx, known, xs), np.interp(idx, known, ys)


def smooth(a: np.ndarray, sigma: float) -> np.ndarray:
    if sigma < 0.5 or len(a) < 3:
        return a
    r = int(math.ceil(sigma * 3))
    k = np.exp(-0.5 * (np.arange(-r, r + 1) / sigma) ** 2)
    k /= k.sum()
    pad = np.pad(a, r, mode="edge")
    return np.convolve(pad, k, mode="valid")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out", dest="dst", required=True)
    ap.add_argument("--x", type=float, required=True)
    ap.add_argument("--y", type=float, required=True)
    ap.add_argument("--frame", type=int, default=0)
    ap.add_argument("--aspect", default="9:16")
    ap.add_argument("--zoom", type=float, default=1.5)
    ap.add_argument("--smooth", type=int, default=0, help="0 = locked on the face, 100 = slow follow")
    ap.add_argument("--anchor-y", type=float, default=0.5, help="where the eyes sit, 0 = top, 1 = bottom")
    ap.add_argument("--edge", default="clamp", choices=["clamp", "blur"])
    ap.add_argument("--models", default="/var/www/mvcut/vendor_ml/face")
    ap.add_argument("--crf", default="18")
    a = ap.parse_args()

    v = probe(a.src)
    W, H, fps = v["w"], v["h"], v["fps"]
    diag = math.hypot(W, H)

    faces = analyse(a.src, v, a.models)
    n = len(faces)
    if n == 0:
        print("no frames decoded", file=sys.stderr)
        return 2
    start = pick_start(faces, max(0, min(n - 1, a.frame)), a.x, a.y, diag)
    if not start:
        print("no face at point", file=sys.stderr)
        return 4
    track = follow(faces, start[0], start[1], diag)
    seen = sum(1 for t in track if t)
    cx, cy = fill(track)

    # 0 pins the face (only the detector's own jitter is damped), 100 glides like a slow camera
    sigma = max(1.0, 0.03 * fps) + (max(0, min(100, a.smooth)) / 100) ** 2 * 1.0 * fps
    cx, cy = smooth(cx, sigma), smooth(cy, sigma)

    # the window: the largest box of the aspect that fits, shrunk by the zoom
    ar = ASPECTS.get(a.aspect, W / H)
    bw, bh = (H * ar, H) if W / H > ar else (W, W / ar)
    ow, oh = even(bw), even(bh)
    zoom = max(1.0, min(4.0, a.zoom))
    ww, wh = bw / zoom, bh / zoom
    scale = ow / ww
    ay = max(0.2, min(0.8, a.anchor_y))

    enc_v = (["-c:v", "libvpx-vp9", "-crf", "30", "-b:v", "0", "-row-mt", "1", "-cpu-used", "4"]
             if a.dst.lower().endswith(".webm") else
             ["-c:v", "libx264", "-preset", "medium", "-crf", a.crf, "-pix_fmt", "yuv420p", "-movflags", "+faststart"])
    enc = subprocess.Popen(
        ["ffmpeg", "-v", "error", "-y",
         "-f", "rawvideo", "-pix_fmt", "bgr24", "-s", f"{ow}x{oh}", "-r", f"{fps:.6f}", "-i", "-",
         "-i", a.src, "-map", "0:v", "-map", "1:a?", "-c:a", "copy", "-shortest"] + enc_v + [a.dst],
        stdin=subprocess.PIPE)

    dec = decoder(a.src, 0, 0)
    for i, img in enumerate(frames(dec, W, H)):
        j = min(i, n - 1)
        x0, y0 = cx[j] - ww / 2, cy[j] - wh * ay
        if a.edge == "clamp":
            x0 = min(max(0.0, x0), W - ww)
            y0 = min(max(0.0, y0), H - wh)
        m = np.float32([[scale, 0, -x0 * scale], [0, scale, -y0 * scale]])
        if a.edge == "blur" and (x0 < 0 or y0 < 0 or x0 + ww > W or y0 + wh > H):
            # the frame itself, covering the window and blurred, behind the part that exists
            cs = max(ow / W, oh / H)
            bg = cv2.resize(img, (max(ow, int(W * cs) + 1), max(oh, int(H * cs) + 1)), interpolation=cv2.INTER_AREA)
            bx, by = (bg.shape[1] - ow) // 2, (bg.shape[0] - oh) // 2
            bg = cv2.GaussianBlur(np.ascontiguousarray(bg[by:by + oh, bx:bx + ow]), (0, 0), max(8, ow / 40))
            out = cv2.warpAffine(img, m, (ow, oh), dst=bg, flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_TRANSPARENT)
        else:
            out = cv2.warpAffine(img, m, (ow, oh), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
        enc.stdin.write(out.tobytes())
        if i % 10 == 0:
            progress(45 + 55 * i / n)
    dec.wait()
    enc.stdin.close()
    if enc.wait() != 0:
        print("encode failed", file=sys.stderr)
        return 1
    progress(100)
    print(json.dumps({"frames": n, "seen": seen, "start": start[0], "size": [ow, oh]}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
