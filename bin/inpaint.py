#!/usr/bin/env python3
"""
Region inpainting with ProPainter, run locally on the GPU.

Takes rectangles in output-frame coordinates, paints them white on a mask image,
hands both to ProPainter's inference script, and muxes the original audio back.

ProPainter is under the NTU S-Lab License 1.0 (non-commercial use only). It is
installed on the server, never vendored into this repository.

Usage:
  inpaint.py --in IN --out OUT --rects '[{"x":0,"y":0,"w":10,"h":10}]' [--dilate 6]

Progress is printed to stderr as "progress <0..100>".
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile


def probe(path: str) -> dict:
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries",
         "stream=width,height,nb_frames,r_frame_rate", "-show_entries", "format=duration",
         "-of", "json", path], capture_output=True, text=True, check=True).stdout
    j = json.loads(out)
    st = j["streams"][0]
    num, den = (st["r_frame_rate"].split("/") + ["1"])[:2]
    return {"w": int(st["width"]), "h": int(st["height"]),
            "fps": float(num) / float(den or 1),
            "frames": int(st.get("nb_frames") or 0),
            "dur": float(j.get("format", {}).get("duration") or 0)}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out", dest="dst", required=True)
    ap.add_argument("--rects", default="[]", help="JSON list of {x,y,w,h} in output pixels")
    ap.add_argument("--outpaint", default="", help="SH,SW scales; generates new frame border instead")
    ap.add_argument("--dilate", type=int, default=6, help="grow the mask, so edges are repainted too")
    ap.add_argument("--propainter", default="/var/www/mvcut/vendor_ml/ProPainter")
    ap.add_argument("--subvideo", type=int, default=50, help="frames per chunk; lower needs less VRAM")
    ap.add_argument("--neighbor", type=int, default=8)
    ap.add_argument("--crf", default="18")
    ap.add_argument("--proc-long", type=int, default=640,
                    help="long edge ProPainter works at; the repainted area is composited back")
    a = ap.parse_args()

    rects = json.loads(a.rects)
    sh = sw = 1.0
    if a.outpaint:
        sh, sw = (float(x) for x in a.outpaint.split(",")[:2])
        sh, sw = max(1.0, min(2.5, sh)), max(1.0, min(2.5, sw))
        if sh == 1.0 and sw == 1.0:
            print("no expansion", file=sys.stderr)
            return 2
    elif not rects:
        print("no rects", file=sys.stderr)
        return 2

    from PIL import Image, ImageDraw

    v = probe(a.src)
    work = tempfile.mkdtemp(prefix="inpaint-")
    try:

        # ProPainter reads videos through torchvision.io.read_video, which current torchvision
        # no longer has. Feeding it a frame folder takes the same path without patching it.
        frames_dir = os.path.join(work, "frames")
        os.makedirs(frames_dir)
        # ProPainter is slow at full HD, so it works on a smaller copy; only the repainted
        # rectangles are taken from its result, the rest of the frame stays original.
        long_edge = max(v["w"], v["h"])
        ratio = min(1.0, a.proc_long / long_edge) if a.proc_long > 0 else 1.0
        pw = max(64, int(v["w"] * ratio) // 8 * 8)
        ph = max(64, int(v["h"] * ratio) // 8 * 8)
        vf = f"scale={pw}:{ph}:flags=bicubic" if (pw, ph) != (v["w"], v["h"]) else None
        ex = subprocess.run(["ffmpeg", "-v", "error", "-i", os.path.abspath(a.src)]
                            + (["-vf", vf] if vf else [])
                            + ["-start_number", "0", os.path.join(frames_dir, "%06d.png")])
        if ex.returncode != 0 or not os.listdir(frames_dir):
            print("frame extraction failed", file=sys.stderr)
            return 6

        mask_path = os.path.join(work, "mask.png")
        img = Image.new("L", (pw, ph), 0)
        d = ImageDraw.Draw(img)
        sx, sy = pw / v["w"], ph / v["h"]
        for r in rects:
            x0 = max(0, int((int(r["x"]) - a.dilate) * sx))
            y0 = max(0, int((int(r["y"]) - a.dilate) * sy))
            x1 = min(pw, int((int(r["x"]) + int(r["w"]) + a.dilate) * sx))
            y1 = min(ph, int((int(r["y"]) + int(r["h"]) + a.dilate) * sy))
            d.rectangle([x0, y0, max(x0, x1 - 1), max(y0, y1 - 1)], fill=255)
        img.save(mask_path)

        out_dir = os.path.join(work, "results")
        cmd = [sys.executable, "inference_propainter.py",
               "--video", frames_dir,
               "--save_fps", str(max(1, int(round(v["fps"])))),
               "--output", out_dir,
               "--subvideo_length", str(a.subvideo),
               "--neighbor_length", str(a.neighbor),
               "--mask_dilation", "0",
               "--fp16"]
        if a.outpaint:
            cmd += ["--mode", "video_outpainting", "--scale_h", str(sh), "--scale_w", str(sw)]
        else:
            cmd += ["--mask", mask_path]
        print("progress 5", file=sys.stderr, flush=True)
        proc = subprocess.Popen(cmd, cwd=a.propainter, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, text=True, bufsize=1)
        tail = []
        for line in proc.stdout:
            tail.append(line.rstrip())
            del tail[:-40]
            m = re.search(r"(\d+)\s*%", line)
            if m:
                print(f"progress {min(95, 5 + int(int(m.group(1)) * 0.9))}", file=sys.stderr, flush=True)
        rc = proc.wait()
        if rc != 0:
            print("\n".join(tail[-20:]), file=sys.stderr)
            return 3

        made = None
        for root, _dirs, files in os.walk(out_dir):
            for f in files:
                if f.endswith(".mp4") and "inpaint" in f:
                    made = os.path.join(root, f)
        if not made:
            print("propainter produced no video\n" + "\n".join(tail[-20:]), file=sys.stderr)
            return 4

        if a.outpaint:
            # the generated border is what we want; the middle keeps the original pixels
            fw = max(2, int(v["w"] * sw) // 2 * 2)
            fh = max(2, int(v["h"] * sh) // 2 * 2)
            x0 = (fw - v["w"]) // 2 // 2 * 2
            y0 = (fh - v["h"]) // 2 // 2 * 2
            mux = ["ffmpeg", "-v", "error", "-y", "-i", made, "-i", os.path.abspath(a.src),
                   "-filter_complex",
                   f"[0:v]scale={fw}:{fh}:flags=bicubic[bg];[bg][1:v]overlay={x0}:{y0}[vout]",
                   "-map", "[vout]", "-map", "1:a?", "-c:a", "copy",
                   "-c:v", "libx264", "-preset", "medium", "-crf", a.crf,
                   "-pix_fmt", "yuv420p", "-movflags", "+faststart", os.path.abspath(a.dst)]
            if subprocess.run(mux).returncode != 0:
                return 5
            print("progress 100", file=sys.stderr, flush=True)
            return 0

        # composite: original frame, with the repainted rectangles pasted back at full size
        boxes = []
        for r in rects:
            x0 = max(0, int(r["x"]) - a.dilate)
            y0 = max(0, int(r["y"]) - a.dilate)
            bw = min(v["w"] - x0, int(r["w"]) + a.dilate * 2)
            bh = min(v["h"] - y0, int(r["h"]) + a.dilate * 2)
            if bw > 1 and bh > 1:
                boxes.append((x0, y0, bw, bh))

        # crop each repainted box out of the upscaled result and overlay it on the original
        chain = [f"[1:v]scale={v['w']}:{v['h']}:flags=bicubic,split={len(boxes)}"
                 + "".join(f"[s{i}]" for i in range(len(boxes)))]
        for i, (x0, y0, bw, bh) in enumerate(boxes):
            chain.append(f"[s{i}]crop={bw}:{bh}:{x0}:{y0}[p{i}]")
        cur = "[0:v]"
        for i, (x0, y0, _bw, _bh) in enumerate(boxes):
            out_lbl = "[vout]" if i == len(boxes) - 1 else f"[c{i}]"
            chain.append(f"{cur}[p{i}]overlay={x0}:{y0}{out_lbl}")
            cur = out_lbl

        mux = ["ffmpeg", "-v", "error", "-y", "-i", os.path.abspath(a.src), "-i", made,
               "-filter_complex", ";".join(chain),
               "-map", "[vout]", "-map", "0:a?", "-c:a", "copy",
               "-c:v", "libx264", "-preset", "medium", "-crf", a.crf,
               "-pix_fmt", "yuv420p", "-movflags", "+faststart", os.path.abspath(a.dst)]
        if subprocess.run(mux).returncode != 0:
            return 5
        print("progress 100", file=sys.stderr, flush=True)
        return 0
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
