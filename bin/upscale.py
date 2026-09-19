#!/usr/bin/env python3
"""
Detail restoration with Real-ESRGAN's compact models (SRVGGNetCompact), run locally.

The network always produces 4x; --out-scale says what to keep, so 1.0 restores
detail at the original size and 2.0 doubles it. Frames stream through ffmpeg, and
tiling keeps large frames inside the GPU's memory.

Usage:
  upscale.py --in IN --out OUT [--model general|anime] [--out-scale 1.0] [--tile 512]

Progress is printed to stderr as "progress <0..100>".
"""
import argparse
import json
import subprocess
import sys

import torch
from torch import nn
from torch.nn import functional as F


class SRVGGNetCompact(nn.Module):
    """Real-ESRGAN's compact generator: a plain VGG-style body plus a pixel-shuffle tail."""

    def __init__(self, num_in_ch=3, num_out_ch=3, num_feat=64, num_conv=16, upscale=4):
        super().__init__()
        self.upscale = upscale
        body = [nn.Conv2d(num_in_ch, num_feat, 3, 1, 1), nn.PReLU(num_parameters=num_feat)]
        for _ in range(num_conv):
            body += [nn.Conv2d(num_feat, num_feat, 3, 1, 1), nn.PReLU(num_parameters=num_feat)]
        body.append(nn.Conv2d(num_feat, num_out_ch * upscale * upscale, 3, 1, 1))
        self.body = nn.Sequential(*body)
        self.upsampler = nn.PixelShuffle(upscale)

    def forward(self, x):
        out = self.upsampler(self.body(x))
        return out + F.interpolate(x, scale_factor=self.upscale, mode="nearest")


def load(path: str) -> SRVGGNetCompact:
    sd = torch.load(path, map_location="cpu", weights_only=True)
    sd = sd.get("params", sd)
    convs = sorted(int(k.split(".")[1]) for k in sd if k.startswith("body.") and k.endswith(".weight")
                   and sd[k].dim() == 4)
    num_conv = len(convs) - 2                      # head and tail are not counted
    num_feat = sd["body.0.weight"].shape[0]
    net = SRVGGNetCompact(num_feat=num_feat, num_conv=num_conv)
    net.load_state_dict(sd, strict=True)
    return net


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


def run_tiled(net, x, tile: int, pad: int = 16):
    """Runs the net tile by tile so a 10GB card can handle 1080p and above."""
    if tile <= 0:
        return net(x)
    _, _, h, w = x.shape
    out = torch.empty((1, 3, h * 4, w * 4), dtype=x.dtype, device=x.device)
    for y0 in range(0, h, tile):
        for x0 in range(0, w, tile):
            y1, x1 = min(y0 + tile, h), min(x0 + tile, w)
            py0, px0 = max(0, y0 - pad), max(0, x0 - pad)
            py1, px1 = min(h, y1 + pad), min(w, x1 + pad)
            piece = net(x[:, :, py0:py1, px0:px1])
            ty0, tx0 = (y0 - py0) * 4, (x0 - px0) * 4
            out[:, :, y0 * 4:y1 * 4, x0 * 4:x1 * 4] = piece[:, :, ty0:ty0 + (y1 - y0) * 4,
                                                            tx0:tx0 + (x1 - x0) * 4]
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out", dest="dst", required=True)
    ap.add_argument("--model", default="general", choices=["general", "anime"])
    ap.add_argument("--weights-dir", default="/var/www/mvcut/vendor_ml")
    ap.add_argument("--out-scale", type=float, default=1.0)
    ap.add_argument("--tile", type=int, default=512)
    ap.add_argument("--crf", default="18")
    a = ap.parse_args()

    if not torch.cuda.is_available():
        print("cuda not available", file=sys.stderr)
        return 3
    device = torch.device("cuda")
    torch.set_grad_enabled(False)
    torch.backends.cudnn.benchmark = True

    weights = f"{a.weights_dir}/" + ("realesr-animevideov3.pth" if a.model == "anime"
                                     else "realesr-general-x4v3.pth")
    net = load(weights).to(device).eval().half()

    v = probe(a.src)
    w, h, fps = v["w"], v["h"], v["fps"]
    ow = max(2, int(round(w * a.out_scale)) // 2 * 2)
    oh = max(2, int(round(h * a.out_scale)) // 2 * 2)

    dec = subprocess.Popen(
        ["ffmpeg", "-v", "error", "-i", a.src, "-f", "rawvideo", "-pix_fmt", "rgb24", "-"],
        stdout=subprocess.PIPE)
    enc = subprocess.Popen(
        ["ffmpeg", "-v", "error", "-y",
         "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{ow}x{oh}", "-r", f"{fps:.6f}", "-i", "-",
         "-i", a.src, "-map", "0:v", "-map", "1:a?", "-c:a", "copy",
         "-c:v", "libx264", "-preset", "medium", "-crf", a.crf, "-pix_fmt", "yuv420p",
         "-movflags", "+faststart", a.dst],
        stdin=subprocess.PIPE)

    frame_bytes = w * h * 3
    done, step, last = 0, 0, -1
    while True:
        buf = dec.stdout.read(frame_bytes)
        if not buf or len(buf) != frame_bytes:
            break
        x = torch.frombuffer(bytearray(buf), dtype=torch.uint8).to(device)
        x = x.reshape(h, w, 3).permute(2, 0, 1).half().div_(255.0).unsqueeze(0)
        y = run_tiled(net, x, a.tile)
        if (ow, oh) != (w * 4, h * 4):
            y = F.interpolate(y.float(), size=(oh, ow), mode="area" if a.out_scale < 4 else "bicubic")
        y = y.clamp(0, 1).mul(255).byte().squeeze(0).permute(1, 2, 0)
        enc.stdin.write(y.cpu().numpy().tobytes())
        done += 1
        step += 1
        if step >= 5:
            step = 0
            pct = min(99, int(done * 100 / max(1, v["frames"])))
            if pct != last:
                last = pct
                print(f"progress {pct}", file=sys.stderr, flush=True)

    enc.stdin.close()
    dec.stdout.close()
    dec.wait()
    rc = enc.wait()
    print("progress 100", file=sys.stderr, flush=True)
    return 0 if rc == 0 else 5


if __name__ == "__main__":
    sys.exit(main())
