#!/usr/bin/env python3
"""
Download copy with camera metadata.

  camera_meta.py extract <camera.MP4> <template.json>
      Pulls the file-level metadata of a real camera clip (Sony XAVC S) into a template.
  camera_meta.py apply <src> <dst> <template.json|->
      Remuxes <src> without any metadata (container tags, chapters, data/subtitle tracks,
      encoder SEI). For MP4/MOV with a template, rebuilds the header so it carries the
      camera's ftyp, PROF uuid, times, handler names, USMT uuids and NRT XML.

exit 0 ok, 2 usage, 3 ffmpeg failed
"""
import json, os, re, struct, subprocess, sys, tempfile

CONT = {b'moov', b'trak', b'mdia', b'minf', b'stbl', b'dinf', b'edts', b'udta'}


# ---------------------------------------------------------------- boxes
class Box:
    def __init__(self, typ, payload=b'', kids=None):
        self.typ, self.payload, self.kids = typ, payload, kids

    def raw(self):
        body = b''.join(k.raw() for k in self.kids) if self.kids is not None else self.payload
        n = len(body) + 8
        if n > 0xFFFFFFFF:
            return struct.pack('>I4sQ', 1, self.typ, n + 8) + body
        return struct.pack('>I4s', n, self.typ) + body

    def find(self, typ):
        return [k for k in (self.kids or []) if k.typ == typ]


def parse(buf, off=0, end=None):
    end = len(buf) if end is None else end
    out = []
    while off + 8 <= end:
        sz, typ = struct.unpack('>I4s', buf[off:off + 8]); hl = 8
        if sz == 1:
            sz = struct.unpack('>Q', buf[off + 8:off + 16])[0]; hl = 16
        elif sz == 0:
            sz = end - off
        body = buf[off + hl:off + sz]
        out.append(Box(typ, kids=parse(body)) if typ in CONT else Box(typ, body))
        off += sz
    return out


def top_level(path):
    """[(type, offset, header_len, size)] without reading mdat."""
    res, size = [], os.path.getsize(path)
    with open(path, 'rb') as f:
        off = 0
        while off + 8 <= size:
            f.seek(off); h = f.read(16)
            sz, typ = struct.unpack('>I4s', h[:8]); hl = 8
            if sz == 1:
                sz = struct.unpack('>Q', h[8:16])[0]; hl = 16
            elif sz == 0:
                sz = size - off
            res.append((typ, off, hl, sz))
            off += sz
    return res


def read_box(path, entry):
    typ, off, hl, sz = entry
    with open(path, 'rb') as f:
        f.seek(off + hl)
        return f.read(sz - hl)


def handler(trak):
    mdia = trak.find(b'mdia')[0]
    return mdia.find(b'hdlr')[0].payload[8:12]


def times(full_payload):
    """(ctime, mtime) of mvhd/tkhd/mdhd, both versions."""
    if full_payload[0] == 1:
        return struct.unpack('>QQ', full_payload[4:20])
    return struct.unpack('>II', full_payload[4:12])


def set_times(box, ct, mt):
    p = bytearray(box.payload)
    if p[0] == 1:
        p[4:20] = struct.pack('>QQ', ct, mt)
    else:
        p[4:12] = struct.pack('>II', ct & 0xFFFFFFFF, mt & 0xFFFFFFFF)
    box.payload = bytes(p)


# ---------------------------------------------------------------- extract
def extract(src, out):
    ents = top_level(src)
    tpl = {'traks': {}}
    for e in ents:
        typ = e[0]
        if typ == b'ftyp':
            tpl['ftyp'] = read_box(src, e).hex()
        elif typ == b'uuid' and 'prof' not in tpl:
            tpl['prof'] = read_box(src, e).hex()
        elif typ == b'mdat':
            tpl['mdat_at'] = e[1]
        elif typ == b'meta':
            tpl['meta'] = read_box(src, e).hex()
        elif typ == b'moov':
            moov = Box(b'moov', kids=parse(read_box(src, e)))
            tpl['mvhd_times'] = times(moov.find(b'mvhd')[0].payload)
            tpl['moov_uuid'] = [k.payload.hex() for k in moov.find(b'uuid')]
            for t in moov.find(b'trak'):
                h = handler(t).decode('latin1')
                mdia = t.find(b'mdia')[0]
                hp = mdia.find(b'hdlr')[0].payload
                d = {'tkhd_times': times(t.find(b'tkhd')[0].payload),
                     'mdhd_times': times(mdia.find(b'mdhd')[0].payload),
                     'hdlr_name': hp[24:].hex(),
                     'uuid': [k.payload.hex() for k in t.find(b'uuid')]}
                stsd = mdia.find(b'minf')[0].find(b'stbl')[0].find(b'stsd')[0].payload
                if h == 'vide' and len(stsd) >= 90:
                    d['compressor'] = stsd[58:90].hex()
                tpl['traks'].setdefault(h, d)
    with open(out, 'w') as f:
        json.dump(tpl, f)


# ---------------------------------------------------------------- xml
def fps_label(fps):
    for v, s in [(23.976, '23.98p'), (24, '24p'), (25, '25p'), (29.97, '29.97p'), (30, '30p'),
                 (50, '50p'), (59.94, '59.94p'), (60, '60p'), (100, '100p'), (119.88, '119.88p'), (120, '120p')]:
        if abs(fps - v) < 0.02:
            return s
    return ('%.2f' % fps).rstrip('0').rstrip('.') + 'p'


def bcd(n):
    return (n // 10) << 4 | (n % 10)


def unbcd(b):
    return (b >> 4) * 10 + (b & 0x0F)


def ltc_end(start_hex, end_hex, frames, fps, tc_fps):
    s = bytes.fromhex(start_hex); e = bytes.fromhex(end_hex)
    ff, ss, mm, hh = unbcd(s[0] & 0x3F), unbcd(s[1] & 0x7F), unbcd(s[2] & 0x7F), unbcd(s[3] & 0x3F)
    total = ((hh * 60 + mm) * 60 + ss) * tc_fps + ff + int((frames - 1) * tc_fps / fps)
    ff = total % tc_fps; total //= tc_fps
    ss = total % 60; total //= 60
    mm = total % 60; hh = (total // 60) % 24
    return bytes([bcd(ff) | (e[0] & 0xC0), bcd(ss) | (e[1] & 0x80), bcd(mm) | (e[2] & 0x80),
                  bcd(hh) | (e[3] & 0xC0)]).hex().upper()


def ratio(w, h):
    from math import gcd
    g = gcd(w, h) or 1
    r = (w // g, h // g)
    return {(16, 9): '16:9', (9, 16): '9:16', (4, 3): '4:3', (1, 1): '1:1'}.get(r, '%d:%d' % r)


def patch_xml(xml, w, h, frames, fps, codec):
    xml = re.sub(r'(<Duration value=")\d+', lambda m: m.group(1) + str(frames), xml)
    m = re.search(r'<LtcChangeTable tcFps="(\d+)"[^>]*>\s*<LtcChange frameCount="0" value="([0-9A-Fa-f]{8})"[^>]*/>'
                  r'\s*<LtcChange frameCount="(\d+)" value="([0-9A-Fa-f]{8})" status="end"/>', xml)
    if m:
        end = ltc_end(m.group(2), m.group(4), frames, fps, int(m.group(1)))
        seg = m.group(0)
        seg = re.sub(r'frameCount="\d+" value="[0-9A-Fa-f]{8}" status="end"',
                     'frameCount="%d" value="%s" status="end"' % (max(0, frames - 1), end), seg)
        xml = xml.replace(m.group(0), seg)
    lab = fps_label(fps)
    xml = re.sub(r'(captureFps=")[^"]*', lambda m: m.group(1) + lab, xml)
    xml = re.sub(r'(formatFps=")[^"]*', lambda m: m.group(1) + lab, xml)
    xml = re.sub(r'(videoCodec="[A-Z]+_)\d+_\d+', lambda m: m.group(1) + '%d_%d' % (w, h), xml)
    if codec == 'hvc1':
        xml = re.sub(r'videoCodec="AVC_', 'videoCodec="HEVC_', xml)
    xml = re.sub(r'(<VideoLayout pixel=")\d+(" numOfVerticalLine=")\d+(" aspectRatio=")[^"]*',
                 lambda m: m.group(1) + str(w) + m.group(2) + str(h) + m.group(3) + ratio(w, h), xml)
    return xml


# ---------------------------------------------------------------- apply
def ffmpeg_strip(src, dst, vcodec):
    ext = os.path.splitext(dst)[1].lower()
    # audio is re-encoded: a copied ffmpeg AAC stream carries "Lavc..." in its first frame
    audio = {'.webm': ['-c:a', 'libopus', '-b:a', '160k'], '.gif': ['-an']}.get(ext, ['-c:a', 'aac', '-b:a', '256k'])
    args = ['ffmpeg', '-v', 'error', '-y', '-i', src, '-map', '0:v:0', '-map', '0:a:0?',
            '-c:v', 'copy'] + audio + ['-map_metadata', '-1', '-map_metadata:s', '-1', '-map_chapters', '-1',
            '-fflags', '+bitexact', '-flags:v', '+bitexact', '-flags:a', '+bitexact']
    if vcodec == 'h264':
        args += ['-bsf:v', 'filter_units=remove_types=6']        # SEI (x264 settings string etc.)
    elif vcodec == 'hevc':
        args += ['-bsf:v', 'filter_units=remove_types=39|40']
    args.append(dst)
    r = subprocess.run(args, capture_output=True, text=True)
    if r.returncode != 0:
        sys.stderr.write(r.stderr); sys.exit(3)


def vcodec_of(src):
    r = subprocess.run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries',
                        'stream=codec_name', '-of', 'csv=p=0', src], capture_output=True, text=True)
    return r.stdout.strip()


def apply(src, dst, tpl_path):
    ext = os.path.splitext(dst)[1].lower()
    vcodec = vcodec_of(src)
    tpl = None
    if tpl_path != '-' and ext in ('.mp4', '.mov', '.m4v') and os.path.isfile(tpl_path):
        with open(tpl_path) as f:
            tpl = json.load(f)
    fd, tmp = tempfile.mkstemp(suffix=ext, dir=os.path.dirname(dst)); os.close(fd)
    try:
        ffmpeg_strip(src, tmp, vcodec)
        if tpl is None:
            os.replace(tmp, dst); return
        rebuild(tmp, dst, tpl)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def rebuild(tmp, dst, tpl):
    ents = top_level(tmp)
    mdat = [e for e in ents if e[0] == b'mdat'][0]
    moov = Box(b'moov', kids=parse(read_box(tmp, [e for e in ents if e[0] == b'moov'][0])))

    moov.kids = [k for k in moov.kids if k.typ not in (b'udta', b'meta', b'uuid')]
    set_times(moov.find(b'mvhd')[0], *tpl['mvhd_times'])
    w = h = frames = 0; fps = 30.0; codec = 'avc1'
    for t in moov.find(b'trak'):
        t.kids = [k for k in t.kids if k.typ not in (b'udta', b'meta', b'uuid')]
        hd = handler(t).decode('latin1')
        src = tpl['traks'].get(hd)
        mdia = t.find(b'mdia')[0]
        mdhd = mdia.find(b'mdhd')[0]
        stbl = mdia.find(b'minf')[0].find(b'stbl')[0]
        if hd == 'vide':
            tk = t.find(b'tkhd')[0].payload
            wh = tk[-8:]
            w, h = struct.unpack('>I', wh[:4])[0] >> 16, struct.unpack('>I', wh[4:])[0] >> 16
            frames = struct.unpack('>I', stbl.find(b'stsz')[0].payload[8:12])[0]
            mp = mdhd.payload
            ts = struct.unpack('>I', mp[20:24] if mp[0] == 1 else mp[12:16])[0]
            stts = stbl.find(b'stts')[0].payload
            runs = [struct.unpack('>II', stts[8 + k * 8:16 + k * 8]) for k in range(struct.unpack('>I', stts[4:8])[0])]
            if runs:
                delta = max(runs, key=lambda r: r[0])[1]   # most common frame duration
                if delta:
                    fps = ts / delta
            stsd = stbl.find(b'stsd')[0]
            p = bytearray(stsd.payload)
            codec = p[12:16].decode('latin1')
            if src and src.get('compressor') and codec == 'avc1':
                p[58:90] = bytes.fromhex(src['compressor'])
            stsd.payload = bytes(p)
        if not src:
            continue
        set_times(t.find(b'tkhd')[0], *src['tkhd_times'])
        set_times(mdhd, *src['mdhd_times'])
        hdlr = mdia.find(b'hdlr')[0]
        hdlr.payload = hdlr.payload[:24] + bytes.fromhex(src['hdlr_name'])
        t.kids += [Box(b'uuid', bytes.fromhex(u)) for u in src['uuid']]
    moov.kids += [Box(b'uuid', bytes.fromhex(u)) for u in tpl.get('moov_uuid', [])]

    # PROF uuid: VPRF carries the frame size
    prof = bytearray(bytes.fromhex(tpl['prof']))
    i = prof.find(b'VPRF')
    if i >= 4 and w:
        prof[i - 4 + 44:i - 4 + 48] = struct.pack('>HH', w, h)
    head = Box(b'ftyp', bytes.fromhex(tpl['ftyp'])).raw() + Box(b'uuid', bytes(prof)).raw()
    mdat_hl = 16 if mdat[3] - 8 > 0xFFFFFFFF else 8
    new_mdat = max(tpl.get('mdat_at', 0), len(head) + 8)
    head += Box(b'free', b'\0' * (new_mdat - len(head) - 8)).raw()
    delta = (new_mdat + mdat_hl) - (mdat[1] + mdat[2])

    for t in moov.find(b'trak'):
        stbl = t.find(b'mdia')[0].find(b'minf')[0].find(b'stbl')[0]
        for b in stbl.kids:
            if b.typ in (b'stco', b'co64'):
                p = bytearray(b.payload)
                n = struct.unpack('>I', p[4:8])[0]
                fmt, sz = ('>I', 4) if b.typ == b'stco' else ('>Q', 8)
                vals = [struct.unpack(fmt, p[8 + k * sz:8 + (k + 1) * sz])[0] + delta for k in range(n)]
                if b.typ == b'stco' and max(vals, default=0) > 0xFFFFFFFF:
                    b.typ, fmt = b'co64', '>Q'
                b.payload = bytes(p[:8]) + b''.join(struct.pack(fmt, v) for v in vals)

    meta = b''
    if tpl.get('meta'):
        mb = bytes.fromhex(tpl['meta'])
        kids = parse(mb, 4)
        for k in kids:
            if k.typ == b'xml ':
                xml = k.payload[4:].rstrip(b'\0').decode('utf-8')
                k.payload = k.payload[:4] + patch_xml(xml, w, h, frames, fps, codec).encode('utf-8') + b'\0'
        meta = Box(b'meta', mb[:4] + b''.join(k.raw() for k in kids)).raw()

    with open(tmp, 'rb') as fi, open(dst + '.part', 'wb') as fo:
        fo.write(head)
        size = mdat[3] - mdat[2]
        fo.write(struct.pack('>I4sQ', 1, b'mdat', size + 16) if mdat_hl == 16 else struct.pack('>I4s', size + 8, b'mdat'))
        fi.seek(mdat[1] + mdat[2])
        copy_n(fi, fo, size)
        fo.write(moov.raw())
        fo.write(meta)
    os.replace(dst + '.part', dst)


def copy_n(fi, fo, n):
    while n > 0:
        b = fi.read(min(n, 8 << 20))
        if not b:
            break
        fo.write(b); n -= len(b)


if __name__ == '__main__':
    if len(sys.argv) == 4 and sys.argv[1] == 'extract':
        extract(sys.argv[2], sys.argv[3])
    elif len(sys.argv) == 5 and sys.argv[1] == 'apply':
        apply(sys.argv[2], sys.argv[3], sys.argv[4])
    else:
        sys.stderr.write(__doc__); sys.exit(2)
