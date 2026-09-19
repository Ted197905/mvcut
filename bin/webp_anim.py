#!/usr/bin/env python3
"""
Converts an animated WebP into an MP4.

ffmpeg's webp demuxer only reads single images, so the frames are decoded with
Pillow (which honours each frame's own duration) and fed to ffmpeg through the
concat demuxer, keeping the original timing.

Usage:  webp_anim.py --in IN.webp --out OUT.mp4 [--crf 18]
Prints the frame count and duration as JSON on stdout.
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out", dest="dst", required=True)
    ap.add_argument("--crf", default="18")
    a = ap.parse_args()

    from PIL import Image

    work = tempfile.mkdtemp(prefix="webp-")
    try:
        im = Image.open(a.src)
        n = getattr(im, "n_frames", 1)
        if n < 2:
            print("not animated", file=sys.stderr)
            return 2

        durations = []
        for i in range(n):
            im.seek(i)
            frame = im.convert("RGB")
            # even dimensions, so yuv420p encoders accept it
            w, h = frame.size
            if w % 2 or h % 2:
                frame = frame.crop((0, 0, w - w % 2, h - h % 2))
            frame.save(os.path.join(work, f"{i:06d}.png"))
            durations.append(max(0.01, im.info.get("duration", 100) / 1000.0))

        listing = os.path.join(work, "list.txt")
        with open(listing, "w", encoding="utf-8") as fh:
            for i, d in enumerate(durations):
                fh.write(f"file '{i:06d}.png'\nduration {d:.4f}\n")
            fh.write(f"file '{n - 1:06d}.png'\n")   # concat needs the last frame twice

        cmd = ["ffmpeg", "-v", "error", "-y", "-f", "concat", "-safe", "0", "-i", listing,
               "-vsync", "vfr", "-c:v", "libx264", "-preset", "medium", "-crf", a.crf,
               "-pix_fmt", "yuv420p", "-movflags", "+faststart", os.path.abspath(a.dst)]
        if subprocess.run(cmd).returncode != 0 or not os.path.exists(a.dst):
            return 3
        print(json.dumps({"frames": n, "duration": round(sum(durations), 3)}))
        return 0
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
