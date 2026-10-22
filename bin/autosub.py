#!/usr/bin/env python3
"""
MV Cut auto subtitles: speech (or burned-in captions) -> Korean cues.

  1. faster-whisper large-v3 (GPU) transcribes with word timestamps and detects the language.
  2. SenseVoice (CPU, zh/en/ja/ko/yue) transcribes each segment again as a second opinion.
  3. No usable speech -> frames are sampled and a local vision model (Ollama) reads on-screen text.
  4. A local LLM (Ollama) reconciles the two transcripts and translates into short Korean lines.

Writes JSON: {"language","source":"speech"|"ocr","cues":[{"start","end","text","src"}],"engines":{...}}
Progress goes to stderr as "progress N".
"""
import argparse
import difflib
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.request
import wave

import numpy as np

SV_LANGS = {"zh", "en", "ja", "ko", "yue"}
LANG_NAMES = {"en": "English", "ja": "Japanese", "zh": "Chinese", "vi": "Vietnamese", "ko": "Korean",
              "yue": "Cantonese", "th": "Thai", "id": "Indonesian", "es": "Spanish", "fr": "French"}


def progress(n: int) -> None:
    sys.stderr.write(f"progress {int(n)}\n")
    sys.stderr.flush()


def log(msg: str) -> None:
    sys.stderr.write(msg + "\n")
    sys.stderr.flush()


def extract_wav(src: str, dst: str) -> bool:
    r = subprocess.run(["ffmpeg", "-y", "-v", "error", "-i", src, "-vn", "-ac", "1", "-ar", "16000", "-f", "wav", dst],
                       capture_output=True, text=True)
    return r.returncode == 0 and os.path.isfile(dst) and os.path.getsize(dst) > 1000


def read_wav(path: str) -> np.ndarray:
    with wave.open(path) as w:
        return np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768.0


# ---------------------------------------------------------------- whisper
def run_whisper(wav: str, model_dir: str, lang: str | None) -> tuple[list[dict], str, float]:
    import torch  # noqa: F401  (loads the CUDA libraries ctranslate2 needs)
    from faster_whisper import WhisperModel

    model = WhisperModel(model_dir, device="cuda", compute_type="int8_float16")
    segs, info = model.transcribe(wav, language=lang, beam_size=5, vad_filter=True, word_timestamps=True,
                                  condition_on_previous_text=False)
    out = []
    for s in segs:
        words = [{"start": w.start, "end": w.end, "word": w.word} for w in (s.words or [])]
        text = s.text.strip()
        if not text:
            continue
        start = words[0]["start"] if words else s.start
        end = words[-1]["end"] if words else s.end
        out.append({"start": float(start), "end": float(end), "text": text, "words": words,
                    "no_speech": float(s.no_speech_prob), "avg_logprob": float(s.avg_logprob)})
    language, prob = info.language, float(info.language_probability)
    del model
    try:
        import torch
        torch.cuda.empty_cache()
    except Exception:  # noqa: BLE001
        pass
    return out, language, prob


def split_cues(cues: list[dict], max_len: float = 6.0, max_chars: int = 36) -> list[dict]:
    """A phone subtitle: a long translated line is cut in two at a space near its middle, the
    time split in proportion to the characters (the source text stays whole on both halves)."""
    out = []
    for c in cues:
        text = c["text"]
        dur = c["end"] - c["start"]
        if (dur <= max_len and len(text) <= max_chars) or " " not in text.strip() or dur < 1.6:
            out.append(c)
            continue
        mid = len(text) // 2
        cands = [k for k, ch in enumerate(text) if ch == " " and 0 < k < len(text) - 1]
        # prefer a cut after punctuation / a Korean clause ending near the middle
        best = min(cands, key=lambda k: abs(k - mid) - (6 if text[k - 1] in ",.!?~고서면데" else 0))
        a, b = text[:best].strip(), text[best:].strip()
        if not a or not b:
            out.append(c)
            continue
        t = c["start"] + dur * len(a) / (len(a) + len(b))
        out.extend(split_cues([dict(c, end=round(t, 3), text=a), dict(c, start=round(t, 3), text=b)], max_len, max_chars))
    return out


# ---------------------------------------------------------------- sensevoice
def run_sensevoice(audio: np.ndarray, segs: list[dict], model_dir: str, lang: str) -> list[str]:
    import sherpa_onnx

    rec = sherpa_onnx.OfflineRecognizer.from_sense_voice(
        model=os.path.join(model_dir, "model.int8.onnx"), tokens=os.path.join(model_dir, "tokens.txt"),
        num_threads=4, use_itn=True, language=lang if lang in SV_LANGS else "auto")
    out = []
    for s in segs:
        a = int(max(0.0, s["start"] - 0.15) * 16000)
        b = int(min(len(audio) / 16000, s["end"] + 0.15) * 16000)
        st = rec.create_stream()
        st.accept_waveform(16000, audio[a:b])
        rec.decode_stream(st)
        out.append(st.result.text.strip())
    return out


# ---------------------------------------------------------------- ollama
def ollama(url: str, model: str, messages: list[dict], schema: dict | None = None, timeout: int = 600,
           images: list[str] | None = None) -> str:
    body = {"model": model, "messages": messages, "stream": False, "think": False,
            "options": {"temperature": 0.2, "num_ctx": 8192}}
    if schema:
        body["format"] = schema
    if images:
        body["messages"][-1]["images"] = images
    req = urllib.request.Request(url.rstrip("/") + "/api/chat", data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        j = json.loads(r.read().decode())
    return (j.get("message") or {}).get("content") or ""


def ollama_unload(url: str, model: str) -> None:
    try:
        req = urllib.request.Request(url.rstrip("/") + "/api/generate",
                                     data=json.dumps({"model": model, "keep_alive": 0}).encode(),
                                     headers={"Content-Type": "application/json"})
        urllib.request.urlopen(req, timeout=30).read()
    except Exception:  # noqa: BLE001
        pass


TRANSLATE_SCHEMA = {
    "type": "object",
    "properties": {"lines": {"type": "array", "items": {
        "type": "object",
        "properties": {"i": {"type": "integer"}, "src": {"type": "string"}, "ko": {"type": "string"}},
        "required": ["i", "src", "ko"]}}},
    "required": ["lines"],
}


def translate(url: str, model: str, segs: list[dict], alt: list[str] | None, language: str, context: str,
              source: str) -> list[dict]:
    lang_name = LANG_NAMES.get(language, language)
    rows = []
    for i, s in enumerate(segs):
        row = f'{i}\t{s["text"]}'
        if alt and i < len(alt) and alt[i] and alt[i] != s["text"]:
            row += f"\t[2nd engine: {alt[i]}]"
        rows.append(row)
    what = ("speech transcribed by two speech-recognition engines" if alt
            else "speech transcribed by a speech-recognition engine" if source == "speech"
            else "on-screen captions read from the video frames")
    system = (
        "You prepare Korean subtitles for short social-media videos. "
        f"Input: numbered {lang_name} lines, {what}. Where a second engine's reading is given, the two may disagree: "
        "pick the reading that makes sense in context, fix obvious recognition errors, and keep proper nouns. "
        "For every input line output exactly one object: i (same number), src (the corrected source text, same language), "
        "ko (natural, concise Korean a Korean viewer would read as a subtitle: casual spoken register matching the speaker, "
        "no romanization, keep names/brands, under 40 characters when possible; a filler-only line becomes a short natural Korean filler). "
        "Each ko must translate ONLY its own line i (the neighbouring lines are context, not content). "
        "Never merge, drop or reorder lines. Output JSON only.")
    if context:
        system += f"\nContext about the video (title/description, may be in any language): {context[:600]}"
    content = ollama(url, model, [{"role": "system", "content": system},
                                  {"role": "user", "content": "\n".join(rows)}], TRANSLATE_SCHEMA)
    try:
        lines = json.loads(content).get("lines") or []
    except ValueError:
        raise RuntimeError("translation model returned no JSON") from None
    by_i = {}
    for ln in lines:
        try:
            by_i[int(ln.get("i"))] = ln
        except (TypeError, ValueError):
            continue
    out = []
    for i, s in enumerate(segs):
        ln = by_i.get(i) or {}
        ko = str(ln.get("ko") or "").strip()
        src = str(ln.get("src") or "").strip() or s["text"]
        if not ko:
            raise RuntimeError(f"translation missing for line {i}")
        out.append({"start": round(s["start"], 3), "end": round(s["end"], 3), "text": ko[:200], "src": src[:200]})
    return out


# ---------------------------------------------------------------- OCR fallback
def sample_frames(src: str, duration: float, out_dir: str, step: float) -> list[tuple[float, str]]:
    pattern = os.path.join(out_dir, "f%05d.jpg")
    r = subprocess.run(["ffmpeg", "-y", "-v", "error", "-i", src, "-vf", f"fps=1/{step},scale=640:-2",
                        "-q:v", "4", pattern], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError("frame sampling failed: " + r.stderr[-300:])
    files = sorted(f for f in os.listdir(out_dir) if f.startswith("f") and f.endswith(".jpg"))
    return [(k * step, os.path.join(out_dir, f)) for k, f in enumerate(files)]


OCR_SCHEMA = {"type": "object", "properties": {"text": {"type": "string"}}, "required": ["text"]}


def run_ocr(src: str, duration: float, url: str, model: str, lang: str | None, at: int, span: int) -> list[dict]:
    import base64

    step = 0.5 if duration <= 20 else 1.0
    with tempfile.TemporaryDirectory(prefix="autosub-") as d:
        frames = sample_frames(src, duration, d, step)
        if not frames:
            return []
        hint = f" The captions are most likely {LANG_NAMES.get(lang, lang)}." if lang and lang != "auto" else ""
        system = ("You read subtitles / captions burned into a video frame. Return the caption text exactly as written, "
                  "in its original language, joining multiple lines with a space. Ignore watermarks, usernames, "
                  "channel logos, UI buttons and tiny text. If there is no caption, return an empty string." + hint)
        raw = []
        for k, (t, path) in enumerate(frames):
            with open(path, "rb") as fh:
                img = base64.b64encode(fh.read()).decode()
            try:
                content = ollama(url, model, [{"role": "system", "content": system},
                                              {"role": "user", "content": "Caption text in this frame?"}],
                                 OCR_SCHEMA, timeout=120, images=[img])
                text = str(json.loads(content).get("text") or "").strip()
            except Exception as e:  # noqa: BLE001
                log(f"ocr frame {k} failed: {e}")
                text = ""
            raw.append((t, " ".join(text.split())))
            progress(at + int(span * (k + 1) / len(frames)))
    # consecutive frames with the same (or nearly the same) caption form one cue
    cues: list[dict] = []
    for t, text in raw:
        if not text or len(text) < 2:
            continue
        if cues and difflib.SequenceMatcher(None, cues[-1]["text"], text).ratio() > 0.8 and t - cues[-1]["end"] <= step + 0.01:
            cues[-1]["end"] = t + step
            if len(text) > len(cues[-1]["text"]):
                cues[-1]["text"] = text
            continue
        cues.append({"start": t, "end": t + step, "text": text, "words": []})
    # a caption seen in a single frame of a 1 fps sample is usually noise
    cues = [c for c in cues if c["end"] - c["start"] >= step * 2 - 0.01 or len(cues) <= 2]
    for c in cues:
        c["end"] = min(duration, c["end"])
    return cues


# ---------------------------------------------------------------- main
def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--lang", default="auto")
    ap.add_argument("--duration", type=float, default=0)
    ap.add_argument("--context", default="")
    ap.add_argument("--whisper", required=True, help="faster-whisper model dir")
    ap.add_argument("--sensevoice", default="", help="sherpa-onnx SenseVoice model dir")
    ap.add_argument("--ollama", default="http://localhost:11434")
    ap.add_argument("--llm", default="huihui_ai/Qwen3.6-abliterated:35b-a3b-q4_K")
    ap.add_argument("--vlm", default="huihui_ai/qwen3-vl-abliterated:8b-instruct")
    a = ap.parse_args()
    lang = None if a.lang in ("", "auto") else a.lang
    t0 = time.time()
    engines: dict = {}

    with tempfile.TemporaryDirectory(prefix="autosub-") as d:
        wav = os.path.join(d, "a.wav")
        has_audio = extract_wav(a.src, wav)
        progress(5)
        segs: list[dict] = []
        language = lang or ""
        if has_audio:
            segs, language, prob = run_whisper(wav, a.whisper, lang)
            engines["whisper"] = {"language": language, "probability": round(prob, 3),
                                  "segments": [{"start": round(s["start"], 2), "end": round(s["end"], 2), "text": s["text"]} for s in segs]}
            log(f"whisper: {len(segs)} segments, {language} ({prob:.2f}), {time.time() - t0:.1f}s")
            # hallucinated or near-silent audio: little speech, low confidence
            speech = sum(s["end"] - s["start"] for s in segs if s["no_speech"] < 0.6)
            if speech < 1.0 or (prob < 0.4 and speech < 3.0):
                log(f"little speech ({speech:.1f}s): falling back to on-screen text")
                segs = []
        progress(40)

        source = "speech" if segs else "ocr"
        alt = None
        if segs:
            if a.sensevoice and language in SV_LANGS and os.path.isdir(a.sensevoice):
                try:
                    alt = run_sensevoice(read_wav(wav), segs, a.sensevoice, language)
                    engines["sensevoice"] = alt
                    log(f"sensevoice: {sum(1 for x in alt if x)} of {len(alt)} segments, {time.time() - t0:.1f}s")
                except Exception as e:  # noqa: BLE001
                    log(f"sensevoice failed: {e}")
            progress(55)
        else:
            if a.duration <= 0:
                raise SystemExit("no speech and no --duration for frame sampling")
            segs = run_ocr(a.src, a.duration, a.ollama, a.vlm, lang, 40, 30)
            engines["ocr"] = [{"start": round(s["start"], 2), "end": round(s["end"], 2), "text": s["text"]} for s in segs]
            log(f"ocr: {len(segs)} captions, {time.time() - t0:.1f}s")
            ollama_unload(a.ollama, a.vlm)
            if not segs:
                json.dump({"language": language, "source": source, "cues": [], "engines": engines},
                          open(a.out, "w", encoding="utf-8"), ensure_ascii=False)
                progress(100)
                return 0
            if not language:
                language = lang or "auto"
            progress(70)

    cues = translate(a.ollama, a.llm, segs, alt, language, a.context, source)
    log(f"translated {len(cues)} lines, {time.time() - t0:.1f}s")
    cues = split_cues(cues)
    progress(98)
    with open(a.out, "w", encoding="utf-8") as fh:
        json.dump({"language": language, "source": source, "cues": cues, "engines": engines}, fh, ensure_ascii=False)
    progress(100)
    return 0


if __name__ == "__main__":
    sys.exit(main())
