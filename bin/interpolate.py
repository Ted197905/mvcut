#!/usr/bin/env python3
"""
Frame interpolation with RIFE (Practical-RIFE 4.25), run locally on the GPU.

Reads the video through ffmpeg as raw RGB, generates factor-1 intermediate frames
between every pair, and writes the result through ffmpeg. Audio is copied from the
source, its timing untouched: the caller sets speed before this step.

Usage:
  interpolate.py --in IN --out OUT --factor 2 [--scale 1.0] [--rife DIR]

Progress is printed to stderr as "progress <0..100>".
"""
import argparse
import json
import os
import subprocess
import sys

# a cut between shots has nothing to interpolate; blending across it smears
SCENE_DIFF = 0.30


def probe(path: str) -> dict:
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries",
         "stream=width,height,r_frame_rate,nb_frames", "-show_entries", "format=duration",
         "-of", "json", path],
        capture_output=True, text=True, check=True).stdout
    j = json.loads(out)
    st = j["streams"][0]
    num, den = (st["r_frame_rate"].split("/") + ["1"])[:2]
    fps = float(num) / float(den or 1)
    dur = float(j.get("format", {}).get("duration") or 0)
    frames = int(st.get("nb_frames") or 0) or int(round(dur * fps))
    return {"w": int(st["width"]), "h": int(st["height"]), "fps": fps, "frames": max(1, frames)}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out", dest="dst", required=True)
    ap.add_argument("--factor", type=int, default=2)
    ap.add_argument("--scale", type=float, default=1.0)
    ap.add_argument("--rife", default="/var/www/mvcut/vendor_ml/Practical-RIFE")
    ap.add_argument("--crf", default="18")
    a = ap.parse_args()
    factor = max(2, min(8, a.factor))

    import torch
    sys.path.insert(0, a.rife)
    os.chdir(a.rife)
    from train_log.RIFE_HDv3 import Model  # noqa: E402

    if not torch.cuda.is_available():
        print("cuda not available", file=sys.stderr)
        return 3
    device = torch.device("cuda")
    torch.set_grad_enabled(False)
    torch.backends.cudnn.benchmark = True

    model = Model()
    model.load_model(os.path.join(a.rife, "train_log"), -1)
    model.eval()
    model.device()

    v = probe(a.src)
    w, h, fps = v["w"], v["h"], v["fps"]
    # RIFE needs both sides padded to a multiple of 64 (of 32 at half scale)
    tmp = max(32, int(128 / a.scale))
    pw = ((w - 1) // tmp + 1) * tmp
    ph = ((h - 1) // tmp + 1) * tmp
    pad = (0, pw - w, 0, ph - h)

    dec = subprocess.Popen(
        ["ffmpeg", "-v", "error", "-i", a.src, "-f", "rawvideo", "-pix_fmt", "rgb24", "-"],
        stdout=subprocess.PIPE)
    enc = subprocess.Popen(
        ["ffmpeg", "-v", "error", "-y",
         "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{w}x{h}", "-r", f"{fps * factor:.6f}", "-i", "-",
         "-i", a.src, "-map", "0:v", "-map", "1:a?", "-c:a", "copy",
         "-c:v", "libx264", "-preset", "medium", "-crf", a.crf, "-pix_fmt", "yuv420p",
         "-movflags", "+faststart", a.dst],
        stdin=subprocess.PIPE)

    frame_bytes = w * h * 3

    def read():
        buf = dec.stdout.read(frame_bytes)
        return buf if buf and len(buf) == frame_bytes else None

    def to_tensor(buf):
        t = torch.frombuffer(bytearray(buf), dtype=torch.uint8).to(device)
        t = t.reshape(h, w, 3).permute(2, 0, 1).float().div_(255.0).unsqueeze(0)
        return torch.nn.functional.pad(t, pad)

    def write(t):
        x = t[:, :, :h, :w].clamp(0, 1).mul(255).byte().squeeze(0).permute(1, 2, 0)
        enc.stdin.write(x.cpu().numpy().tobytes())

    prev_buf = read()
    if prev_buf is None:
        print("no frames", file=sys.stderr)
        return 4
    prev = to_tensor(prev_buf)
    write(prev)

    done, total = 1, v["frames"]
    step, last_pct = 0, -1
    while True:
        cur_buf = read()
        if cur_buf is None:
            break
        cur = to_tensor(cur_buf)
        # a hard cut: hold the previous frame instead of inventing a blend
        cut = torch.mean(torch.abs(cur - prev)).item() > SCENE_DIFF
        for i in range(1, factor):
            write(prev if cut else model.inference(prev, cur, i / factor, a.scale))
        write(cur)
        prev = cur
        done += 1
        step += 1
        if step >= 10:
            step = 0
            pct = min(99, int(done * 100 / max(1, total)))
            if pct != last_pct:
                last_pct = pct
                print(f"progress {pct}", file=sys.stderr, flush=True)

    enc.stdin.close()
    dec.stdout.close()
    dec.wait()
    rc = enc.wait()
    print("progress 100", file=sys.stderr, flush=True)
    return 0 if rc == 0 else 5


if __name__ == "__main__":
    sys.exit(main())
