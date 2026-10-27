#!/usr/bin/env python3
"""
MV Cut auto subtitles: speech (or burned-in captions) -> Korean cues.

  1. faster-whisper large-v3 (GPU) transcribes with word timestamps and detects the language.
  2. A CTC forced aligner (MMS 300m, GPU) re-times every word against the audio: whisper's own
     timestamps drift by seconds behind music or pauses, the aligner does not.
  3. SenseVoice (CPU, zh/en/ja/ko/yue) transcribes each segment again as a second opinion.
  4. No usable speech -> frames are sampled and a local vision model (Ollama) reads on-screen text.
  5. A local LLM (Ollama) reconciles the two transcripts and translates into short Korean lines.

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

os.environ.setdefault("HF_HUB_OFFLINE", "1")
os.environ.setdefault("HF_HUB_DISABLE_PROGRESS_BARS", "1")
os.environ.setdefault("TRANSFORMERS_VERBOSITY", "error")

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


# ---------------------------------------------------------------- forced alignment
ALIGN_WINDOW = 60.0   # seconds of audio the aligner sees at once
ALIGN_PAD = 6.0       # whisper segment edges can be seconds off, so a window reaches past them
ALIGN_TAIL = 0.3      # a cue lingers this long after the last word (CTC ends are tight)


def align_words(model, vocab: dict, rom, audio: np.ndarray, words: list[str]) -> list:
    """Viterbi CTC alignment of the romanised words over the aligner's frame emissions.
    Returns (start, end) seconds within `audio` per word, None for a word with no letters."""
    import torch

    toks, owner = [], []
    for wi, w in enumerate(words):
        for ch in rom.romanize_string(w).lower():
            if ch in vocab and ch not in "<":
                toks.append(vocab[ch]); owner.append(wi)
    x = torch.from_numpy(audio)
    x = (x - x.mean()) / (x.std() + 1e-7)
    with torch.inference_mode():
        logits = model(x[None].to("cuda", torch.float16)).logits[0].float()
    emis = torch.log_softmax(logits, dim=-1)
    T, N = emis.shape[0], len(toks)
    if not N or N > T:
        return [None] * len(words)
    tokens = torch.tensor(toks, device=emis.device)
    ninf = torch.tensor([-float("inf")], device=emis.device)
    # trellis[t, j] = best score with j tokens emitted after t frames; back marks "emitted here"
    trellis = torch.full((T + 1, N + 1), -float("inf"), device=emis.device)
    trellis[0, 0] = 0
    back = torch.zeros((T + 1, N + 1), dtype=torch.bool, device=emis.device)
    blank = emis[:, 0]
    for t in range(T):
        stay = trellis[t] + blank[t]
        move = torch.cat([ninf, trellis[t, :-1] + emis[t, tokens]])
        back[t + 1] = move > stay
        trellis[t + 1] = torch.maximum(stay, move)
    frames = [None] * N
    b = back.cpu().numpy()
    j = N
    for t in range(T, 0, -1):
        if j > 0 and b[t, j]:
            frames[j - 1] = t - 1
            j -= 1
    spf = len(audio) / 16000 / T
    out = [None] * len(words)
    for wi in range(len(words)):
        fr = [frames[k] for k in range(N) if owner[k] == wi and frames[k] is not None]
        if fr:
            out[wi] = (min(fr) * spf, (max(fr) + 1) * spf)
    return out


def run_align(audio: np.ndarray, segs: list[dict], model_dir: str) -> int:
    """Re-times every whisper word with the MMS forced aligner (1100+ languages through
    romanisation) and gives each segment the span of its words. Returns the word count."""
    import torch
    import uroman
    from transformers import Wav2Vec2ForCTC

    vocab = json.load(open(os.path.join(model_dir, "vocab.json"), encoding="utf-8"))
    rom = uroman.Uroman()
    for s in segs:
        if not s.get("words"):   # whisper gave no word times: spread the text evenly for now
            parts = s["text"].split() or [s["text"]]
            step = (s["end"] - s["start"]) / len(parts)
            s["words"] = [{"start": s["start"] + i * step, "end": s["start"] + (i + 1) * step, "word": " " + w}
                          for i, w in enumerate(parts)]
    model = Wav2Vec2ForCTC.from_pretrained(model_dir, torch_dtype=torch.float16).to("cuda").eval()
    sr, total = 16000, len(audio) / 16000
    done = 0
    i = 0
    while i < len(segs):
        j = i
        while j + 1 < len(segs) and segs[j + 1]["end"] - segs[i]["start"] + 2 * ALIGN_PAD <= ALIGN_WINDOW:
            j += 1
        lo = 0.0 if total <= ALIGN_WINDOW + 2 * ALIGN_PAD else max(0.0, segs[i]["start"] - ALIGN_PAD)
        hi = total if total <= ALIGN_WINDOW + 2 * ALIGN_PAD else min(total, segs[j]["end"] + ALIGN_PAD)
        ws = [w for s in segs[i:j + 1] for w in s["words"]]
        if hi - lo >= 0.5:
            for w, t in zip(ws, align_words(model, vocab, rom, audio[int(lo * sr):int(hi * sr)], [w["word"] for w in ws])):
                if t:
                    w["start"], w["end"], w["aligned"] = round(lo + t[0], 3), round(lo + t[1], 3), True
                    done += 1
        i = j + 1
    del model
    torch.cuda.empty_cache()
    # words without letters (numbers, symbols) sit between their aligned neighbours
    allw = [w for s in segs for w in s["words"]]
    for k, w in enumerate(allw):
        if w.pop("aligned", False):
            continue
        nxt = next((x["start"] for x in allw[k + 1:] if x.get("aligned")), None)
        if k:
            s0 = allw[k - 1]["end"]
        elif nxt is not None:
            s0 = max(0.0, nxt - 0.5)
        else:
            continue   # nothing aligned at all: whisper's times stay
        e0 = nxt if nxt is not None else s0 + 0.5
        w["start"], w["end"] = round(s0, 3), round(max(s0 + 0.05, min(e0, s0 + 1.0)), 3)
    for k, w in enumerate(allw):   # keep the order whisper gave, window edges can nick a neighbour
        if k and w["start"] < allw[k - 1]["end"]:
            w["start"] = allw[k - 1]["end"]
            w["end"] = max(w["end"], w["start"] + 0.05)
    for n, s in enumerate(segs):
        s["start"] = s["words"][0]["start"]
        s["end"] = s["words"][-1]["end"]
        nxt = segs[n + 1]["words"][0]["start"] if n + 1 < len(segs) else total
        s["end"] = round(min(nxt, s["end"] + ALIGN_TAIL), 3)
    return done


SENT_END = ".!?。！？"
CLAUSE_END = SENT_END + ",、，;"


def pre_split(segs: list[dict], max_len: float = 7.0) -> list[dict]:
    """A whisper segment longer than a subtitle should be is cut at word timestamps: at the
    longest pause if there is a real one, else after a sentence end, else after a comma, else
    near the middle. Each part keeps a full clause, so the translation of one part does not
    bleed into the next. Apart from a pause, no cut leaves a part under three words: a few
    stretched words (sung lines) read better as one long cue than as fragments."""
    out = []
    for s in segs:
        words = s.get("words") or []
        if s["end"] - s["start"] <= max_len or len(words) < 4:
            out.append(s)
            continue
        n = len(words)
        def ends_with(k, chars):
            return words[k]["word"].strip()[-1:] in chars
        cut = None
        gaps = [(words[k + 1]["start"] - words[k]["end"], k + 1) for k in range(1, n - 2)]
        if gaps and max(gaps)[0] >= 0.8:
            cut = max(gaps)[1]
        for chars in (() if cut else (SENT_END, CLAUSE_END)):
            cands = [k + 1 for k in range(2, n - 3) if ends_with(k, chars)
                     and words[k]["end"] - s["start"] >= 1.5 and s["end"] - words[k + 1]["start"] >= 1.5]
            if cands:
                cut = min(cands, key=lambda k: abs(k - n / 2))
                break
        if cut is None:
            if n < 6:
                out.append(s)
                continue
            cut = n // 2
        parts = []
        for ws in (words[:cut], words[cut:]):
            txt = "".join(w["word"] for w in ws).strip()
            if txt:
                parts.append(dict(s, start=ws[0]["start"], end=ws[-1]["end"], text=txt, words=ws))
        out.extend(pre_split(parts, max_len) if len(parts) == 2 else [s])
    return out


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
        spaces = [k for k, ch in enumerate(text) if ch == " " and 0 < k < len(text) - 1]
        # a cut after punctuation wins when it leaves both halves a reasonable size; else the middle
        punct = [k for k in spaces if text[k - 1] in CLAUSE_END + "~" and 0.25 <= k / len(text) <= 0.75]
        best = min(punct or spaces, key=lambda k: abs(k - mid))
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


def ollama_unload_all(url: str) -> None:
    """Frees the GPU before whisper/aligner load: the collector's small models (translation, judging) may be resident."""
    try:
        with urllib.request.urlopen(url.rstrip("/") + "/api/ps", timeout=10) as r:
            for m in json.loads(r.read()).get("models", []):
                ollama_unload(url, m["name"])
    except Exception:  # noqa: BLE001
        pass


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
    # a caption seen in a single frame, or with no letters in it, is noise (timers, counters, logos)
    cues = [c for c in cues if c["end"] - c["start"] >= step * 2 - 0.01
            and len(c["text"]) >= 2 and any(ch.isalpha() for ch in c["text"])]
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
    ap.add_argument("--aligner", default="", help="MMS forced aligner model dir (transformers)")
    ap.add_argument("--ollama", default="http://localhost:11434")
    ap.add_argument("--llm", default="huihui_ai/Qwen3.6-abliterated:35b-a3b-q4_K")
    ap.add_argument("--vlm", default="huihui_ai/qwen3-vl-abliterated:8b-instruct")
    a = ap.parse_args()
    lang = None if a.lang in ("", "auto") else a.lang
    t0 = time.time()
    engines: dict = {}
    timing: dict = {}

    with tempfile.TemporaryDirectory(prefix="autosub-") as d:
        wav = os.path.join(d, "a.wav")
        has_audio = extract_wav(a.src, wav)
        progress(5)
        segs: list[dict] = []
        language = lang or ""
        if has_audio:
            ollama_unload_all(a.ollama)
            segs, language, prob = run_whisper(wav, a.whisper, lang)
            engines["whisper"] = {"language": language, "probability": round(prob, 3),
                                  "segments": [{"start": round(s["start"], 2), "end": round(s["end"], 2), "text": s["text"]} for s in segs]}
            timing["whisper"] = round(time.time() - t0, 1)
            log(f"whisper: {len(segs)} segments, {language} ({prob:.2f}), {time.time() - t0:.1f}s")
            # whisper's own silence rule: a segment is noise when it is both probably non-speech
            # and decoded with low confidence (sung lyrics often score high no_speech alone)
            segs = [s for s in segs if not (s["no_speech"] > 0.6 and s["avg_logprob"] < -1.0)]
            speech = sum(s["end"] - s["start"] for s in segs)
            if speech < 1.0 or (prob < 0.4 and speech < 3.0):
                log(f"little speech ({speech:.1f}s): falling back to on-screen text")
                segs = []
            if segs and a.aligner and os.path.isdir(a.aligner):
                try:
                    n = run_align(read_wav(wav), segs, a.aligner)
                    engines["aligned"] = [{"start": round(s["start"], 2), "end": round(s["end"], 2)} for s in segs]
                    timing["align"] = round(time.time() - t0, 1)
                    log(f"aligned {n} words, {time.time() - t0:.1f}s")
                except Exception as e:  # noqa: BLE001
                    log(f"align failed, keeping whisper times: {e}")
        progress(40)

        source = "speech" if segs else "ocr"
        alt = None
        if segs:
            segs = pre_split(segs)
            if a.sensevoice and language in SV_LANGS and os.path.isdir(a.sensevoice):
                try:
                    alt = run_sensevoice(read_wav(wav), segs, a.sensevoice, language)
                    engines["sensevoice"] = alt
                    timing["sensevoice"] = round(time.time() - t0, 1)
                    log(f"sensevoice: {sum(1 for x in alt if x)} of {len(alt)} segments, {time.time() - t0:.1f}s")
                except Exception as e:  # noqa: BLE001
                    log(f"sensevoice failed: {e}")
            progress(55)
        else:
            if a.duration <= 0:
                raise SystemExit("no speech and no --duration for frame sampling")
            segs = run_ocr(a.src, a.duration, a.ollama, a.vlm, lang, 40, 30)
            engines["ocr"] = [{"start": round(s["start"], 2), "end": round(s["end"], 2), "text": s["text"]} for s in segs]
            timing["ocr"] = round(time.time() - t0, 1)
            log(f"ocr: {len(segs)} captions, {time.time() - t0:.1f}s")
            ollama_unload(a.ollama, a.vlm)
            if not segs:
                json.dump({"language": language, "source": source, "cues": [], "engines": engines, "timing": timing},
                          open(a.out, "w", encoding="utf-8"), ensure_ascii=False)
                progress(100)
                return 0
            if not language:
                language = lang or "auto"
            progress(70)

    cues = translate(a.ollama, a.llm, segs, alt, language, a.context, source)
    timing["translate"] = round(time.time() - t0, 1)
    log(f"translated {len(cues)} lines, {time.time() - t0:.1f}s")
    cues = split_cues(cues)
    progress(98)
    with open(a.out, "w", encoding="utf-8") as fh:
        json.dump({"language": language, "source": source, "cues": cues, "engines": engines, "timing": timing}, fh, ensure_ascii=False)
    progress(100)
    return 0


if __name__ == "__main__":
    sys.exit(main())
