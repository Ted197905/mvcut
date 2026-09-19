#!/usr/bin/env python3
"""
Turns a click into a per-frame mask with SAM 2, so the inpainter can follow a
moving object instead of covering its whole path with one box.

The points are given on one frame; SAM 2 propagates the selection forwards and
backwards through the clip and writes one PNG mask per frame.

Usage:
  segment.py --in IN --out-dir DIR --frame 12 \
             --points '[{"x":320,"y":540,"label":1}]' [--proc-long 768]

Prints "progress <0..100>" to stderr and the mask count to stdout.
"""
import argparse
import json
import os
import subprocess
import sys

CHECKPOINTS = {
    "small": ("sam2.1_hiera_small.pt", "configs/sam2.1/sam2.1_hiera_s.yaml"),
    "tiny": ("sam2.1_hiera_tiny.pt", "configs/sam2.1/sam2.1_hiera_t.yaml"),
    "base": ("sam2.1_hiera_base_plus.pt", "configs/sam2.1/sam2.1_hiera_b+.yaml"),
    "large": ("sam2.1_hiera_large.pt", "configs/sam2.1/sam2.1_hiera_l.yaml"),
}


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
    return {"w": int(st["width"]), "h": int(st["height"]), "fps": fps,
            "frames": int(st.get("nb_frames") or 0) or int(round(dur * fps))}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out-dir", dest="out", required=True)
    ap.add_argument("--points", required=True,
                    help='JSON list of {x,y,label}; label 1 keeps, 0 excludes')
    ap.add_argument("--frame", type=int, default=0, help="frame the points were clicked on")
    ap.add_argument("--model", default="small", choices=list(CHECKPOINTS))
    ap.add_argument("--weights-dir", default="/var/www/mvcut/vendor_ml/sam2")
    ap.add_argument("--proc-long", type=int, default=768,
                    help="long edge SAM 2 runs at; masks are written at that size")
    a = ap.parse_args()

    pts = json.loads(a.points)
    if not pts:
        print("no points", file=sys.stderr)
        return 2

    import numpy as np
    import torch
    from PIL import Image
    from sam2.build_sam import build_sam2_video_predictor

    if not torch.cuda.is_available():
        print("cuda not available", file=sys.stderr)
        return 3

    v = probe(a.src)
    ratio = min(1.0, a.proc_long / max(v["w"], v["h"])) if a.proc_long > 0 else 1.0
    pw = max(64, int(v["w"] * ratio) // 2 * 2)
    ph = max(64, int(v["h"] * ratio) // 2 * 2)

    os.makedirs(a.out, exist_ok=True)
    frames_dir = os.path.join(a.out, "_frames")
    os.makedirs(frames_dir, exist_ok=True)
    ex = subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", a.src,
                         "-vf", f"scale={pw}:{ph}:flags=bicubic", "-q:v", "2",
                         "-start_number", "0", os.path.join(frames_dir, "%06d.jpg")])
    if ex.returncode != 0 or not os.listdir(frames_dir):
        print("frame extraction failed", file=sys.stderr)
        return 4
    total = len(os.listdir(frames_dir))
    print("progress 10", file=sys.stderr, flush=True)

    ckpt, cfg = CHECKPOINTS[a.model]
    predictor = build_sam2_video_predictor(cfg, os.path.join(a.weights_dir, ckpt), device="cuda")

    sx, sy = pw / v["w"], ph / v["h"]
    coords = np.array([[p["x"] * sx, p["y"] * sy] for p in pts], dtype=np.float32)
    labels = np.array([int(p.get("label", 1)) for p in pts], dtype=np.int32)
    start = max(0, min(total - 1, a.frame))

    written = 0

    def dump(idx, logits):
        nonlocal written
        m = (logits[0] > 0.0).cpu().numpy().astype("uint8") * 255
        if m.ndim == 3:
            m = m[0]
        Image.fromarray(m, mode="L").save(os.path.join(a.out, f"{idx:06d}.png"))
        written += 1

    with torch.autocast("cuda", dtype=torch.bfloat16):
        state = predictor.init_state(video_path=frames_dir)
        predictor.add_new_points_or_box(inference_state=state, frame_idx=start, obj_id=1,
                                        points=coords, labels=labels)
        # forwards from the clicked frame, then backwards to cover the start of the clip
        for idx, _ids, logits in predictor.propagate_in_video(state, start_frame_idx=start):
            dump(idx, logits)
            if written % 20 == 0:
                print(f"progress {min(94, 10 + int(written * 85 / max(1, total)))}",
                      file=sys.stderr, flush=True)
        if start > 0:
            for idx, _ids, logits in predictor.propagate_in_video(state, start_frame_idx=start,
                                                                  reverse=True):
                dump(idx, logits)

    # any frame SAM 2 did not emit gets an empty mask, so the inpainter sees a full sequence
    empty = None
    for i in range(total):
        p = os.path.join(a.out, f"{i:06d}.png")
        if not os.path.exists(p):
            if empty is None:
                empty = Image.new("L", (pw, ph), 0)
            empty.save(p)

    for f in os.listdir(frames_dir):
        os.remove(os.path.join(frames_dir, f))
    os.rmdir(frames_dir)
    print("progress 100", file=sys.stderr, flush=True)
    print(json.dumps({"masks": total, "w": pw, "h": ph}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
