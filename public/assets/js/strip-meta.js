/*
 * strip-meta.js: removes location, device and date metadata from photos and
 * the location from videos in the browser, before they are uploaded (the
 * "remove metadata" box under the upload area), and lists what a file
 * carries for the dashboard's preview (inspect(), renderPanel()): those
 * formats plus PDF, GIF, MP3 and FLAC, which are read but never rewritten.
 *
 * Photos (JPEG, PNG, WebP): lossless, segments are dropped and pixels are
 * never re-encoded. A photo that does not parse is refused rather than sent
 * with its metadata.
 *
 * JPEG keeps what the picture needs to look the same (JFIF, ICC colour
 * profile, Adobe colour transform) and, when the camera rotated it, a
 * minimal Exif block holding the Orientation tag alone; PNG and WebP keep a
 * rotation the same way, in an Exif chunk of their own. Only the primary
 * image is kept: what some phones append after it (a motion-photo video, an
 * HDR gain map, a thumbnail set) is dropped, since it can carry metadata of
 * its own.
 *
 * Videos (MP4, MOV): the user data is removed (recording position,
 * device, software, author, comments, titles: every entry of udta and of the
 * QuickTime/iTunes item lists, ©xyz and loci included) and the creation and
 * modification dates in the movie, track and media headers are zeroed. It
 * all sits in the moov box, a small index at the start or end of the file;
 * only that box is read. Each entry is turned into a "free" box of the same
 * size and zeroed, so no byte moves and the offsets into the media data stay
 * valid. Rotation and structure are not metadata and stay. The media data
 * itself is never read: the result is the original file with those bytes
 * blanked. M4A audio loses only its position: title, artist and cover are
 * what one shares a song with. A video whose boxes do not parse is uploaded
 * as it is (removed: false): unlike a photo, it would rarely be worth
 * refusing.
 *
 * Any other file is returned unchanged.
 */
/* global AESBinHelper */
(() => {
    'use strict';

    // APP1 (Exif, XMP), APP12 (Ducky), APP13 (IPTC), COM.
    const JPEG_DROP = new Set([0xE1, 0xEC, 0xED, 0xFE]);
    const PNG_DROP = new Set(['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME']);
    const WEBP_DROP = new Set(['EXIF', 'XMP ']);

    const ascii = (b, at, n) => String.fromCharCode(...b.subarray(at, at + n));
    const u32be = (b, o) => b[o] * 0x1000000 + ((b[o + 1] << 16) | (b[o + 2] << 8) | b[o + 3]);
    const utf8 = b => new TextDecoder('utf-8', { fatal: false }).decode(b);
    /** u16/u32 readers over a TIFF block, in its byte order (II little, MM big). */
    const tiffReaders = t => {
        const le = t[0] === 0x49;
        return {
            u16: o => (le ? t[o] | (t[o + 1] << 8) : (t[o] << 8) | t[o + 1]),
            u32: o => (le
                ? (t[o] | (t[o + 1] << 8) | (t[o + 2] << 16)) + t[o + 3] * 0x1000000
                : t[o] * 0x1000000 + ((t[o + 1] << 16) | (t[o + 2] << 8) | t[o + 3])),
        };
    };

    // ── JPEG ──────────────────────────────────────────────────────────────────

    /** Exif Orientation (1-8) from an APP1 payload, 1 when absent or unreadable. */
    function exifOrientation(p) {
        if (p.length < 14 || ascii(p, 0, 6) !== 'Exif\0\0') return 1;
        return tiffOrientation(p.subarray(6));
    }

    /** Orientation (1-8) from a TIFF block, 1 when absent or unreadable. */
    function tiffOrientation(t) {
        if (t.length < 8) return 1;
        const { u16, u32 } = tiffReaders(t);
        const ifd = u32(4);
        if (ifd + 2 > t.length) return 1;
        const n = u16(ifd);
        for (let k = 0; k < n; k++) {
            const e = ifd + 2 + k * 12;
            if (e + 12 > t.length) return 1;
            if (u16(e) === 0x0112) {
                const v = u16(e + 8);
                return v >= 1 && v <= 8 ? v : 1;
            }
        }
        return 1;
    }

    /** A TIFF block holding only the Orientation tag (big-endian), 26 bytes. */
    function orientationTiff(v) {
        return new Uint8Array([
            0x4D, 0x4D, 0x00, 0x2A, 0x00, 0x00, 0x00, 0x08, // MM, 42, IFD0 at 8
            0x00, 0x01,                                     // one entry
            0x01, 0x12, 0x00, 0x03, 0x00, 0x00, 0x00, 0x01, // Orientation, SHORT, 1
            0x00, v, 0x00, 0x00,                            // value, padding
            0x00, 0x00, 0x00, 0x00,                         // no next IFD
        ]);
    }

    /** An APP1 segment holding only the Orientation tag. */
    function orientationSegment(v) {
        const seg = new Uint8Array(36);
        seg.set([0xFF, 0xE1, 0x00, 0x22,                    // APP1, length 34
            0x45, 0x78, 0x69, 0x66, 0x00, 0x00]);           // "Exif\0\0"
        seg.set(orientationTiff(v), 10);
        return seg;
    }

    /** Offset just past the primary image's EOI, scanning entropy-coded data. */
    function primaryEnd(b, from) {
        // 0xFF inside scan data is always followed by 0x00 or a marker, so
        // the first FF D9 ends the image.
        for (let j = from; j + 1 < b.length; j++) {
            if (b[j] === 0xFF && b[j + 1] === 0xD9) return j + 2;
        }
        return -1;
    }

    /**
     * The JPEG's segments up to and including the image data, as
     * {marker, start, end} plus the end of the primary image. Null when the
     * structure does not parse. Shared by stripJpeg() and inspectJpeg().
     */
    function jpegSegments(b) {
        const segs = [];
        let i = 2;
        while (i + 1 < b.length) {
            if (b[i] !== 0xFF) return null;
            const m = b[i + 1];
            if (m === 0xFF) { i++; continue; }                  // fill byte
            if (m === 0x01 || (m >= 0xD0 && m <= 0xD7)) {       // no length
                segs.push({ marker: m, start: i, end: i + 2 });
                i += 2;
                continue;
            }
            if (i + 4 > b.length) return null;
            const len = (b[i + 2] << 8) | b[i + 3];
            if (len < 2 || i + 2 + len > b.length) return null;
            if (m === 0xDA) {                                   // SOS: the image data
                const end = primaryEnd(b, i + 2 + len);
                if (end < 0) return null;
                segs.push({ marker: m, start: i, end });
                return { segs, imageEnd: end };
            }
            segs.push({ marker: m, start: i, end: i + 2 + len });
            i += 2 + len;
        }
        return null;
    }

    const jpegPayload = (b, s) => b.subarray(s.start + 4, s.end);
    const isMpf = (b, s) => s.marker === 0xE2 && ascii(jpegPayload(b, s), 0, 4) === 'MPF\0';

    function stripJpeg(b) {
        const parsed = jpegSegments(b);
        if (!parsed) return null;
        const parts = [];
        let orientation = 1;
        let dropped = parsed.imageEnd < b.length;
        for (const s of parsed.segs) {
            if (s.marker === 0xE1) orientation = Math.max(orientation, exifOrientation(jpegPayload(b, s)));
            // APP2 "MPF" indexes the appended images this drops.
            if (JPEG_DROP.has(s.marker) || isMpf(b, s)) {
                dropped = true;
            } else {
                parts.push(b.subarray(s.start, s.end));
            }
        }
        // SOI, then JFIF when present (it must come first), then Orientation.
        const head = [b.subarray(0, 2)];
        if (parts[0][1] === 0xE0) head.push(parts.shift());
        if (orientation !== 1) head.push(orientationSegment(orientation));
        return { parts: [...head, ...parts], dropped };
    }

    // ── PNG ───────────────────────────────────────────────────────────────────

    /** PNG chunks as {type, start, end} through IEND, or null. */
    function pngChunks(b) {
        const chunks = [];
        let i = 8;
        while (i + 12 <= b.length) {
            const type = ascii(b, i + 4, 4);
            const end = i + 12 + u32be(b, i);
            if (end > b.length) return null;
            chunks.push({ type, start: i, end });
            i = end;
            if (type === 'IEND') return chunks;
        }
        return null;
    }

    // CRC-32 (ISO 3309), as every PNG chunk carries over its type and data.
    let _crcTable = null;
    function crc32(bytes) {
        if (!_crcTable) {
            _crcTable = new Uint32Array(256);
            for (let n = 0; n < 256; n++) {
                let c = n;
                for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
                _crcTable[n] = c >>> 0;
            }
        }
        let crc = 0xFFFFFFFF;
        for (const x of bytes) crc = _crcTable[(crc ^ x) & 0xFF] ^ (crc >>> 8);
        return (crc ^ 0xFFFFFFFF) >>> 0;
    }

    /** An eXIf chunk holding only the Orientation tag. */
    function orientationPngChunk(v) {
        const tiff = orientationTiff(v);
        const c = new Uint8Array(12 + tiff.length);
        const dv = new DataView(c.buffer);
        dv.setUint32(0, tiff.length);
        c.set([0x65, 0x58, 0x49, 0x66], 4);                 // "eXIf"
        c.set(tiff, 8);
        dv.setUint32(8 + tiff.length, crc32(c.subarray(4, 8 + tiff.length)));
        return c;
    }

    function stripPng(b) {
        const chunks = pngChunks(b);
        if (!chunks) return null;
        const parts = [b.subarray(0, 8)];
        let dropped = false;
        for (const c of chunks) {
            if (PNG_DROP.has(c.type)) {
                dropped = true;
                // As for JPEG: the rotation stays, in an eXIf of its own,
                // where the original one was (before the image data).
                const v = c.type === 'eXIf' ? tiffOrientation(b.subarray(c.start + 8, c.end - 4)) : 1;
                if (v !== 1) parts.push(orientationPngChunk(v));
            } else {
                parts.push(b.subarray(c.start, c.end));
            }
        }
        return { parts, dropped };
    }

    // ── WebP ──────────────────────────────────────────────────────────────────

    /** RIFF chunks of a WebP as {fourcc, start, end}, or null. */
    function webpChunks(b) {
        const u32 = o => b[o] + (b[o + 1] << 8) + (b[o + 2] << 16) + b[o + 3] * 0x1000000;
        const chunks = [];
        let i = 12;
        while (i + 8 <= b.length) {
            const size = u32(i + 4);
            if (i + 8 + size > b.length) return null;
            const end = Math.min(i + 8 + size + (size & 1), b.length);
            chunks.push({ fourcc: ascii(b, i, 4), start: i, end });
            i = end;
        }
        return chunks;
    }

    /** An EXIF chunk of a WebP holding only the Orientation tag. */
    function orientationWebpChunk(v) {
        const tiff = orientationTiff(v);
        const c = new Uint8Array(8 + tiff.length);          // 26 bytes: no padding
        c.set([0x45, 0x58, 0x49, 0x46], 0);                 // "EXIF"
        new DataView(c.buffer).setUint32(4, tiff.length, true);
        c.set(tiff, 8);
        return c;
    }

    function stripWebp(b) {
        const list = webpChunks(b);
        if (!list) return null;
        // As for JPEG: the rotation stays, in an EXIF chunk of its own. Some
        // writers put JPEG's "Exif\0\0" before the TIFF block.
        let orientation = 1;
        for (const c of list) {
            if (c.fourcc !== 'EXIF') continue;
            const p = b.subarray(c.start + 8, c.end);
            orientation = ascii(p, 0, 6) === 'Exif\0\0' ? exifOrientation(p) : tiffOrientation(p);
        }
        const chunks = [];
        let dropped = false;
        for (const c of list) {
            if (WEBP_DROP.has(c.fourcc)) {
                dropped = true;
                if (c.fourcc === 'EXIF' && orientation !== 1) chunks.push(orientationWebpChunk(orientation));
            } else if (c.fourcc === 'VP8X') {
                // Clear the "has XMP" flag of the header, and "has Exif"
                // unless the rotation is kept.
                const vp8x = b.slice(c.start, c.end);
                vp8x[8] &= orientation !== 1 ? ~0x04 : ~0x0C;
                chunks.push(vp8x);
            } else {
                chunks.push(b.subarray(c.start, c.end));
            }
        }
        const body = chunks.reduce((n, c) => n + c.length, 0);
        const header = new Uint8Array(12);
        header.set(b.subarray(0, 12));
        new DataView(header.buffer).setUint32(4, body + 4, true);
        return { parts: [header, ...chunks], dropped };
    }

    // ── MP4 / MOV ─────────────────────────────────────────────────────────────

    // The moov box is the index, usually well under a megabyte; past this it
    // is not read into memory, and the video is left as it is.
    const MOOV_MAX = 64 * 1024 * 1024;
    // Containers that can hold a location entry, at any depth under moov.
    const ISO_CONTAINERS = new Set(['moov', 'trak', 'mdia', 'udta', 'meta', 'ilst']);
    // Headers whose creation and modification times are zeroed.
    const ISO_DATED = new Set(['mvhd', 'tkhd', 'mdhd']);
    // Major brands of audio-only files (iTunes audio, audiobook, protected).
    const ISO_AUDIO_BRANDS = new Set(['M4A ', 'M4B ', 'M4P ']);
    // QuickTime keys naming a place: Apple's family (ISO6709, name, body,
    // note, accuracy...) and the bare "location" key ffmpeg writes with
    // -movflags use_metadata_tags, next to its loci box.
    const isLocationKey = key =>
        key === 'location' || key.startsWith('com.apple.quicktime.location.');
    const XYZ = '©xyz';

    const slice = async (file, start, end) => new Uint8Array(await file.slice(start, end).arrayBuffer());

    /** Top-level boxes as {type, start, end}, read header by header. */
    async function isoTopBoxes(file) {
        const boxes = [];
        let pos = 0;
        while (pos < file.size) {
            if (pos + 8 > file.size || boxes.length > 4096) return null;
            const h = await slice(file, pos, Math.min(pos + 16, file.size));
            let size = u32be(h, 0);
            if (size === 1) {
                if (h.length < 16) return null;
                size = u32be(h, 8) * 0x100000000 + u32be(h, 12);
            } else if (size === 0) {
                size = file.size - pos;
            }
            if (size < 8 || pos + size > file.size) return null;
            boxes.push({ type: ascii(h, 4, 4), start: pos, end: pos + size });
            pos += size;
        }
        return boxes;
    }

    /** Child boxes of b[start, end) as {type, start, body, end}, or null. */
    function isoChildren(b, start, end) {
        const out = [];
        let i = start;
        while (i < end) {
            if (i + 8 > end) return null;
            let size = u32be(b, i);
            let hdr = 8;
            if (size === 1) {
                if (i + 16 > end) return null;
                size = u32be(b, i + 8) * 0x100000000 + u32be(b, i + 12);
                hdr = 16;
            } else if (size === 0) {
                size = end - i;
            }
            if (size < hdr || i + size > end) return null;
            out.push({ type: ascii(b, i + 4, 4), start: i, body: i + hdr, end: i + size });
            i += size;
        }
        return out;
    }

    /**
     * Where a meta box's children start. ISO 14496-12 makes meta a full box
     * (4 bytes of version and flags first); QuickTime's moov/meta is not.
     * The hdlr child tells which: it is first in both.
     */
    const metaChildStart = (b, box) =>
        box.body + 8 <= box.end && ascii(b, box.body + 4, 4) === 'hdlr' ? box.body : box.body + 4;

    /** Key names of a QuickTime 'keys' box, 1-based as ilst refers to them. */
    function quicktimeKeys(b, box) {
        const names = [];
        let i = box.body + 8;                       // version/flags, entry count
        while (i + 8 <= box.end) {
            const size = u32be(b, i);
            if (size < 8 || i + size > box.end) break;
            names.push(utf8(b.subarray(i + 8, i + size)));
            i += size;
        }
        return names;
    }

    /**
     * Walk moov, calling visit(box, keyName, parentType) for each box inside a
     * container (keyName: the QuickTime key an ilst item stands for, when it
     * has one). False when the structure does not parse.
     */
    function walkMoov(b, visit) {
        const walk = (box, keys) => {
            const from = box.type === 'meta' ? metaChildStart(b, box) : box.body;
            const kids = isoChildren(b, from, box.end);
            if (!kids) return false;
            // A meta's keys box names the items of the ilst next to it.
            const keysBox = box.type === 'meta' ? kids.find(k => k.type === 'keys') : null;
            const names = keysBox ? quicktimeKeys(b, keysBox) : keys;
            for (const k of kids) {
                const index = box.type === 'ilst' ? u32be(b, k.start + 4) : 0;
                visit(k, box.type === 'ilst' ? (names[index - 1] || '') : '', box.type);
                if (ISO_CONTAINERS.has(k.type) && !walk(k, names)) return false;
            }
            return true;
        };
        // A moov over 4 GiB would use the 16-byte header form.
        return walk({ type: 'moov', body: u32be(b, 0) === 1 ? 16 : 8, end: b.length }, []);
    }

    /** The moov box of an MP4/MOV, read into memory, or null. */
    async function readMoov(file) {
        const head = await slice(file, 0, 12);
        if (head.length < 12 || ascii(head, 4, 4) !== 'ftyp') return null;
        const boxes = await isoTopBoxes(file);
        const moov = boxes && boxes.find(x => x.type === 'moov');
        if (!moov || moov.end - moov.start > MOOV_MAX) return null;
        return { moov, bytes: await slice(file, moov.start, moov.end), brand: ascii(head, 8, 4) };
    }

    const isLocationBox = (box, key) =>
        box.type === XYZ || box.type === 'loci' || isLocationKey(key);

    /**
     * True for audio: an iTunes audio brand, or no video track at all (an
     * M4A from ffmpeg or an Android recorder has the generic isom/mp42 brand,
     * and finfo calls it video/mp4). A track is video when its mdia/hdlr
     * handler type is "vide".
     */
    function isoIsAudio({ bytes, brand }) {
        if (ISO_AUDIO_BRANDS.has(brand)) return true;
        let video = false;
        walkMoov(bytes, (box, key, parent) => {
            if (box.type === 'hdlr' && parent === 'mdia' && ascii(bytes, box.body + 8, 4) === 'vide') video = true;
        });
        return !video;
    }

    /**
     * Zero the creation and modification times of an mvhd, tkhd or mdhd
     * (version 0: two 32-bit fields after version and flags; version 1: two
     * 64-bit ones). True when they held anything.
     */
    function zeroIsoTimes(b, box) {
        const width = b[box.body] === 1 ? 16 : 8;
        const from = box.body + 4;
        if (from + width > box.end) return false;
        const had = b.subarray(from, from + width).some(x => x !== 0);
        b.fill(0, from, from + width);
        return had;
    }

    /** The video with its user data and dates blanked in place, see the header. */
    async function stripIso(file) {
        const found = await readMoov(file);
        if (!found) return { file, removed: false };
        const { moov, bytes } = found;
        const audio = isoIsAudio(found);
        let removed = false;
        const ok = walkMoov(bytes, (box, key, parent) => {
            if (!audio && ISO_DATED.has(box.type)) {
                removed = zeroIsoTimes(bytes, box) || removed;
                return;
            }
            // Every user-data entry except the meta box itself (its items are
            // visited on their own), every item of an item list, and the
            // location wherever it is.
            const userData = !audio && ((parent === 'udta' && box.type !== 'meta') || parent === 'ilst');
            if (!userData && !isLocationBox(box, key)) return;
            bytes.set([0x66, 0x72, 0x65, 0x65], box.start + 4);   // "free"
            bytes.fill(0, box.body, box.end);
            removed = true;
        });
        if (!ok || !removed) return { file, removed: false };
        return {
            file: new File(
                [file.slice(0, moov.start), bytes, file.slice(moov.end)],
                file.name,
                { type: file.type, lastModified: file.lastModified }
            ),
            removed: true,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────

    const PHOTO = { jpeg: stripJpeg, png: stripPng, webp: stripWebp };

    /**
     * The format from the first 12 bytes: 'jpeg', 'png', 'webp', 'iso'
     * (MP4/MOV family), 'pdf', 'gif', 'flac', 'mp3', or null.
     */
    function kindOf(h) {
        if (h[0] === 0xFF && h[1] === 0xD8) return 'jpeg';
        if (ascii(h, 0, 8) === '\x89PNG\r\n\x1a\n') return 'png';
        if (ascii(h, 0, 4) === 'RIFF' && ascii(h, 8, 4) === 'WEBP') return 'webp';
        if (ascii(h, 4, 4) === 'ftyp') return 'iso';
        if (ascii(h, 0, 5) === '%PDF-') return 'pdf';
        if (ascii(h, 0, 4) === 'GIF8') return 'gif';
        if (ascii(h, 0, 4) === 'fLaC') return 'flac';
        // An ID3v2 tag, or a bare MPEG audio frame (sync bits; a JPEG's
        // FF D8 does not match them).
        if (ascii(h, 0, 3) === 'ID3' || (h[0] === 0xFF && (h[1] & 0xE0) === 0xE0)) return 'mp3';
        return null;
    }

    /**
     * The file without its photo metadata or video location. Resolves to
     * {file, removed}: removed says whether anything was taken out. Rejects
     * when a JPEG, PNG or WebP does not parse, so the caller can refuse to
     * send it as is.
     */
    async function strip(file) {
        // The first 12 bytes tell the format: any other file (an archive, a
        // document) is never read into memory.
        const kind = kindOf(new Uint8Array(await file.slice(0, 12).arrayBuffer()));
        if (kind === 'iso') return stripIso(file);
        // The other formats are listed by inspect(), never rewritten here.
        if (!PHOTO[kind]) return { file, removed: false };
        const res = PHOTO[kind](new Uint8Array(await file.arrayBuffer()));
        if (!res) throw new Error('unreadable image');
        if (!res.dropped) return { file, removed: false };
        return {
            file: new File(res.parts, file.name, { type: file.type, lastModified: file.lastModified }),
            removed: true,
        };
    }

    // ── Inspection ────────────────────────────────────────────────────────────
    // inspect() lists what a file carries, for the dashboard's preview, so the
    // owner sees at once whether a position or a device is in there. Each entry
    // is {key, value, sensitive}: key is a language-file suffix (meta_<key>),
    // value plain text, and sensitive marks what can identify a place or a
    // person. Values are never sent anywhere.

    const TEXT_MAX = 120;
    const clip = s => {
        const v = String(s).replace(/[\u0000-\u001f\u007f]+/g, ' ').trim();
        return v.length > TEXT_MAX ? v.slice(0, TEXT_MAX) + '…' : v;
    };

    // TIFF tags worth showing: [tag, key, sensitive].
    const IFD0_TAGS = [[0x010F, 'make', false], [0x0110, 'model', false], [0x0131, 'software', false],
        [0x0132, 'date', false], [0x013B, 'author', true], [0x8298, 'copyright', false], [0x010E, 'description', true]];
    const EXIF_TAGS = [[0x9003, 'date_taken', false], [0xA434, 'lens', false], [0xA431, 'serial', true],
        [0xA430, 'owner', true], [0xA435, 'serial', true], [0x9286, 'comment', true]];

    /** Entries from a TIFF block (the body of an Exif segment or chunk). */
    function inspectTiff(t) {
        const out = [];
        if (t.length < 8) return out;
        const { u16, u32 } = tiffReaders(t);
        const SIZES = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8 };
        /** IFD at off as Map(tag → {type, count, at}), at = where the value lives. */
        const ifd = off => {
            const map = new Map();
            if (!off || off + 2 > t.length) return map;
            const n = Math.min(u16(off), 512);
            for (let k = 0; k < n; k++) {
                const e = off + 2 + k * 12;
                if (e + 12 > t.length) break;
                const type = u16(e + 2);
                const count = u32(e + 4);
                const bytes = (SIZES[type] || 1) * count;
                map.set(u16(e), { type, count, at: bytes <= 4 ? e + 8 : u32(e + 8) });
            }
            return map;
        };
        const text = v => (v && v.at + v.count <= t.length ? clip(utf8(t.subarray(v.at, v.at + v.count))) : '');
        const rational = (v, i) => {
            const o = v.at + i * 8;
            if (o + 8 > t.length) return NaN;
            const d = u32(o + 4);
            return d ? u32(o) / d : NaN;
        };
        const add = (map, tags) => tags.forEach(([tag, key, sensitive]) => {
            const v = map.get(tag);
            const s = v && (v.type === 2 || v.type === 7) ? text(v) : '';
            if (s) out.push({ key, value: s, sensitive });
        });

        const ifd0 = ifd(u32(4));
        add(ifd0, IFD0_TAGS);
        const exifPtr = ifd0.get(0x8769);
        if (exifPtr) add(ifd(u32(exifPtr.at)), EXIF_TAGS);
        const gpsPtr = ifd0.get(0x8825);
        if (gpsPtr) {
            const gps = ifd(u32(gpsPtr.at));
            const coord = (refTag, valTag) => {
                const v = gps.get(valTag);
                if (!v || v.type !== 5 || v.count < 3) return NaN;
                const deg = rational(v, 0) + rational(v, 1) / 60 + rational(v, 2) / 3600;
                const ref = gps.get(refTag);
                const r = ref ? String.fromCharCode(t[ref.at]) : '';
                return r === 'S' || r === 'W' ? -deg : deg;
            };
            const lat = coord(1, 2);
            const lon = coord(3, 4);
            if (Number.isFinite(lat) && Number.isFinite(lon)) {
                out.push({ key: 'location', value: `${lat.toFixed(5)}, ${lon.toFixed(5)}`, sensitive: true });
            } else if (gps.size > 1) {
                // GPS tags with no usable position (only a version, a timestamp).
                out.push({ key: 'gps_tags', value: String(gps.size), sensitive: true });
            }
        }
        return out;
    }

    /** Entries from an XMP packet: only whether it is there and holds a position. */
    function inspectXmp(xml) {
        const lat = /GPSLatitude[="> ]+([^"<]+)/.exec(xml);
        const lon = /GPSLongitude[="> ]+([^"<]+)/.exec(xml);
        const out = [{ key: 'xmp', value: '', sensitive: false }];
        if (lat && lon) out.push({ key: 'location', value: clip(`${lat[1]}, ${lon[1]}`), sensitive: true });
        return out;
    }

    function inspectJpeg(b) {
        const parsed = jpegSegments(b);
        if (!parsed) return null;
        const out = [];
        for (const s of parsed.segs) {
            const p = jpegPayload(b, s);
            if (s.marker === 0xE1 && ascii(p, 0, 6) === 'Exif\0\0') out.push(...inspectTiff(p.subarray(6)));
            else if (s.marker === 0xE1 && ascii(p, 0, 4) === 'http') out.push(...inspectXmp(utf8(p)));
            else if (s.marker === 0xED) out.push({ key: 'iptc', value: '', sensitive: false });
            else if (s.marker === 0xFE) out.push({ key: 'comment', value: clip(utf8(p)), sensitive: true });
        }
        if (parsed.imageEnd < b.length) {
            out.push({ key: 'appended', value: AESBinHelper.fmtBytes(b.length - parsed.imageEnd), sensitive: false });
        }
        return out;
    }

    function inspectPng(b) {
        const chunks = pngChunks(b);
        if (!chunks) return null;
        const out = [];
        for (const c of chunks) {
            const data = b.subarray(c.start + 8, c.end - 4);
            if (c.type === 'eXIf') {
                out.push(...inspectTiff(data));
            } else if (c.type === 'tEXt' || c.type === 'iTXt' || c.type === 'zTXt') {
                const nul = data.indexOf(0);
                const name = utf8(data.subarray(0, nul < 0 ? data.length : nul));
                if (name === 'XML:com.adobe.xmp') out.push(...inspectXmp(utf8(data)));
                // tEXt is name\0text; the others are compressed or framed: name only.
                else out.push({ key: 'text', value: clip(c.type === 'tEXt' ? `${name}: ${utf8(data.subarray(nul + 1))}` : name), sensitive: true });
            } else if (c.type === 'tIME') {
                out.push({ key: 'date', value: '', sensitive: false });
            }
        }
        return out;
    }

    function inspectWebp(b) {
        const chunks = webpChunks(b);
        if (!chunks) return null;
        const out = [];
        for (const c of chunks) {
            const data = b.subarray(c.start + 8, c.end);
            if (c.fourcc === 'EXIF') out.push(...inspectTiff(ascii(data, 0, 6) === 'Exif\0\0' ? data.subarray(6) : data));
            else if (c.fourcc === 'XMP ') out.push(...inspectXmp(utf8(data)));
        }
        return out;
    }

    // QuickTime user data and item keys worth showing.
    const ISO_TEXT = {
        '©mak': 'make', '©mod': 'model', '©swr': 'software', '©day': 'date',
        '©too': 'software', '©ART': 'artist', '©nam': 'title', '©alb': 'album',
        '©cmt': 'comment', '©aut': 'author',
        'com.apple.quicktime.make': 'make', 'com.apple.quicktime.model': 'model',
        'com.apple.quicktime.software': 'software', 'com.apple.quicktime.creationdate': 'date',
        'com.apple.quicktime.author': 'author',
    };
    // The bare keys ffmpeg writes with -movflags use_metadata_tags, as it
    // writes the bare "location" (isLocationKey()). Looked up by key name
    // only, never by box type, so a box of the same name is not read as one.
    const ISO_BARE_KEYS = new Map([
        ['make', 'make'], ['model', 'model'], ['title', 'title'], ['artist', 'artist'],
        ['album', 'album'], ['author', 'author'], ['comment', 'comment'],
        ['encoder', 'software'], ['creation_time', 'date'],
    ]);

    /** Text of an iTunes/QuickTime item: its 'data' child, or the classic user-data form. */
    function isoText(b, box) {
        const kids = isoChildren(b, box.body, box.end);
        const data = kids && kids.find(k => k.type === 'data');
        if (data) return clip(utf8(b.subarray(data.body + 8, data.end)));
        // Classic form: 16-bit length, 16-bit language, then the text.
        if (box.body + 4 > box.end) return '';
        const len = (b[box.body] << 8) | b[box.body + 1];
        return clip(utf8(b.subarray(box.body + 4, Math.min(box.body + 4 + len, box.end))));
    }

    /**
     * "lat, lon" from a 3GPP loci box (TS 26.244): version and flags,
     * language, a NUL-terminated place name, a role byte, then longitude,
     * latitude and altitude as signed 16.16 fixed point.
     */
    function lociText(b, box) {
        let i = box.body + 6;
        while (i < box.end && b[i] !== 0) i++;
        i += 2;                                     // the NUL, the role
        if (i + 8 > box.end) return '';
        const fixed = o => new DataView(b.buffer, b.byteOffset + o, 4).getInt32(0) / 65536;
        return `${fixed(i + 4).toFixed(5)}, ${fixed(i).toFixed(5)}`;
    }

    async function inspectIso(file) {
        const found = await readMoov(file);
        if (!found) return null;
        const b = found.bytes;
        const out = [];
        // One row per place: ffmpeg writes the same position both as
        // "location" and as ©xyz, rounded differently (41.90280, 41.90279).
        const places = [];
        const samePlace = value => {
            const p = value.split(',').map(Number);
            if (p.length !== 2 || p.some(Number.isNaN)) return false;
            if (places.some(q => Math.abs(q[0] - p[0]) < 1e-3 && Math.abs(q[1] - p[1]) < 1e-3)) return true;
            places.push(p);
            return false;
        };
        const ok = walkMoov(b, (box, key) => {
            if (isLocationBox(box, key)) {
                // An accuracy radius is removed with the position, but is
                // not a place of its own to list.
                if (key.includes('.accuracy.')) return;
                let value = box.type === 'loci' ? lociText(b, box) : isoText(b, box);
                // ISO 6709 ("+41.9028+012.4964+020.000/") read as "lat, lon".
                const m = /^([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)/.exec(value);
                if (m) value = `${(+m[1]).toFixed(5)}, ${(+m[2]).toFixed(5)}`;
                if (!samePlace(value)) out.push({ key: 'location', value, sensitive: true });
                return;
            }
            if (box.type === 'covr') {
                out.push({ key: 'cover', value: '', sensitive: false });
                return;
            }
            if (box.type === 'mvhd') {
                // Seconds since 1904-01-01; zero when unset (or cleaned).
                const v1 = b[box.body] === 1;
                const secs = v1 ? u32be(b, box.body + 4) * 0x100000000 + u32be(b, box.body + 8) : u32be(b, box.body + 4);
                const when = new Date((secs - 2082844800) * 1000);
                if (secs && !Number.isNaN(when.getTime())) {
                    out.push({ key: 'date', value: when.toISOString().replace('T', ' ').slice(0, 19), sensitive: false });
                }
                return;
            }
            const name = ISO_TEXT[key] || ISO_BARE_KEYS.get(key) || ISO_TEXT[box.type];
            const value = name ? isoText(b, box) : '';
            if (value) out.push({ key: name, value, sensitive: name === 'author' || name === 'comment' });
        });
        return ok ? out : null;
    }

    /** Comments and an XMP packet in a GIF; null when its blocks do not parse. */
    function inspectGif(b) {
        if (b.length < 13) return null;
        const out = [];
        const table = packed => (packed & 0x80 ? 3 * (1 << ((packed & 0x07) + 1)) : 0);
        // The data sub-blocks starting at i, and where they end.
        const subBlocks = i => {
            const parts = [];
            while (i < b.length) {
                const n = b[i];
                if (n === 0) return { data: parts, end: i + 1 };
                parts.push(b.subarray(i + 1, i + 1 + n));
                i += 1 + n;
            }
            return null;
        };
        let i = 13 + table(b[10]);
        while (i < b.length) {
            const block = b[i];
            if (block === 0x3B) return out;                    // trailer
            if (block === 0x21) {                               // extension
                const label = b[i + 1];
                const sb = subBlocks(i + 2);
                if (!sb) return null;
                if (label === 0xFE) {
                    out.push({ key: 'comment', value: clip(sb.data.map(d => utf8(d)).join('')), sensitive: true });
                } else if (label === 0xFF && ascii(b, i + 3, 11) === 'XMP DataXMP') {
                    out.push({ key: 'xmp', value: '', sensitive: false });
                }
                i = sb.end;
            } else if (block === 0x2C) {                        // image
                const sb = subBlocks(i + 10 + table(b[i + 9]) + 1);
                if (!sb) return null;
                i = sb.end;
            } else {
                return null;
            }
        }
        return out;
    }

    // ID3v2 text frames worth showing: id → [key, sensitive].
    const ID3_FRAMES = {
        TIT2: ['title', false], TPE1: ['artist', false], TALB: ['album', false],
        TYER: ['date', false], TDRC: ['date', false], TENC: ['software', false], TSSE: ['software', false],
        TCOM: ['author', false], TEXT: ['author', false], TCOP: ['copyright', false],
    };

    /** Values of an ID3 frame body: an encoding byte, then NUL-separated text. */
    function id3Parts(d) {
        const enc = d[0];
        const label = enc === 1 ? 'utf-16' : enc === 2 ? 'utf-16be' : enc === 3 ? 'utf-8' : 'latin1';
        return new TextDecoder(label).decode(d.subarray(1)).split('\u0000');
    }
    const id3Text = d => clip(id3Parts(d).filter(Boolean).join(', '));

    /** Entries from an MP3's tags: ID3v2 at the start, ID3v1 in the last 128 bytes. */
    async function inspectMp3(blob) {
        const out = [];
        const head = new Uint8Array(await blob.slice(0, 10).arrayBuffer());
        if (ascii(head, 0, 3) === 'ID3' && head[3] >= 3) {
            const v4 = head[3] === 4;
            const syncsafe = (x, o) => ((x[o] & 0x7F) << 21) | ((x[o + 1] & 0x7F) << 14) | ((x[o + 2] & 0x7F) << 7) | (x[o + 3] & 0x7F);
            const b = new Uint8Array(await blob.slice(0, 10 + syncsafe(head, 6)).arrayBuffer());
            let i = 10;
            if (head[5] & 0x40) i += v4 ? syncsafe(b, 10) : 4 + u32be(b, 10);   // extended header
            while (i + 10 <= b.length) {
                const id = ascii(b, i, 4);
                if (!/^[A-Z0-9]{4}$/.test(id)) break;           // padding
                const len = v4 ? syncsafe(b, i + 4) : u32be(b, i + 4);
                const d = b.subarray(i + 10, Math.min(i + 10 + len, b.length));
                if (ID3_FRAMES[id]) {
                    const value = id3Text(d);
                    if (value) out.push({ key: ID3_FRAMES[id][0], value, sensitive: ID3_FRAMES[id][1] });
                } else if (id === 'COMM' && d.length > 4) {
                    // Encoding, a language code, a description, then the text.
                    const value = id3Text(Uint8Array.of(d[0], ...d.subarray(4)));
                    if (value) out.push({ key: 'comment', value, sensitive: true });
                } else if (id === 'TXXX') {
                    // A description, then the value; "comment" is how some
                    // encoders (ffmpeg) store an ordinary comment.
                    const [desc = '', ...rest] = id3Parts(d);
                    const value = clip(rest.filter(Boolean).join(', '));
                    if (value && desc.toLowerCase() === 'comment') out.push({ key: 'comment', value, sensitive: true });
                    else if (value) out.push({ key: 'custom', value: clip(`${desc}: ${value}`), sensitive: true });
                } else if (id === 'APIC') {
                    out.push({ key: 'cover', value: '', sensitive: false });
                }
                i += 10 + len;
            }
        }
        if (blob.size >= 128) {
            const tail = new Uint8Array(await blob.slice(blob.size - 128).arrayBuffer());
            if (ascii(tail, 0, 3) === 'TAG') {
                const field = (o, n) => clip(new TextDecoder('latin1').decode(tail.subarray(o, o + n)).replace(/\u0000.*$/, ''));
                [['title', 3, 30], ['artist', 33, 30], ['album', 63, 30], ['date', 93, 4], ['comment', 97, 30]]
                    .forEach(([key, o, n]) => {
                        const value = field(o, n);
                        if (value) out.push({ key, value, sensitive: key === 'comment' });
                    });
            }
        }
        return out;
    }

    // Vorbis comment fields worth showing: name → [key, sensitive].
    const VORBIS_FIELDS = {
        TITLE: ['title', false], ARTIST: ['artist', false], ALBUM: ['album', false], DATE: ['date', false],
        COMMENT: ['comment', true], DESCRIPTION: ['comment', true], ENCODER: ['software', false],
        LOCATION: ['location', true], CONTACT: ['custom', true], COPYRIGHT: ['copyright', false],
    };

    /** Entries from a FLAC's metadata blocks (Vorbis comments, pictures). */
    async function inspectFlac(blob) {
        const out = [];
        let pos = 4;
        for (let n = 0; n < 64 && pos + 4 <= blob.size; n++) {
            const h = new Uint8Array(await blob.slice(pos, pos + 4).arrayBuffer());
            const type = h[0] & 0x7F;
            const len = (h[1] << 16) | (h[2] << 8) | h[3];
            if (type === 4) {
                const d = new Uint8Array(await blob.slice(pos + 4, pos + 4 + len).arrayBuffer());
                const dv = new DataView(d.buffer);
                let i = 4 + (d.length >= 4 ? dv.getUint32(0, true) : 0);   // vendor string
                const count = i + 4 <= d.length ? dv.getUint32(i, true) : 0;
                i += 4;
                for (let k = 0; k < count && i + 4 <= d.length; k++) {
                    const l = dv.getUint32(i, true);
                    const [name, ...rest] = utf8(d.subarray(i + 4, i + 4 + l)).split('=');
                    const f = VORBIS_FIELDS[name.toUpperCase()];
                    const value = clip(rest.join('='));
                    if (f && value) out.push({ key: f[0], value, sensitive: f[1] });
                    i += 4 + l;
                }
            } else if (type === 6) {
                out.push({ key: 'cover', value: '', sensitive: false });
            }
            if (h[0] & 0x80) break;                             // last block
            pos += 4 + len;
        }
        return out;
    }

    /** "D:20260703123000+02'00'" as "2026-07-03 12:30:00"; anything else as is. */
    const pdfDate = s => {
        const m = /^D:(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?/.exec(s);
        if (!m) return s;
        const [, y, mo = '01', d = '01', h = '00', mi = '00', se = '00'] = m;
        return `${y}-${mo}-${d} ${h}:${mi}:${se}`;
    };

    // Info dictionary keys worth showing: [key, entry key, sensitive].
    const PDF_INFO = [['Author', 'author', true], ['Title', 'title', false], ['Subject', 'subject', false],
        ['Keywords', 'keywords', false], ['Creator', 'software', false], ['Producer', 'producer', false],
        ['CreationDate', 'date', false], ['ModDate', 'modified', false]];

    /**
     * Entries from a PDF, read through pdf.js (the preview's own document,
     * already parsed): the Info dictionary, its custom keys, and the XMP
     * author and tool when they add something.
     */
    async function inspectPdf(pdf) {
        const { info = {}, metadata } = await pdf.getMetadata();
        const out = [];
        for (const [k, key, sensitive] of PDF_INFO) {
            const v = typeof info[k] === 'string' ? info[k].trim() : '';
            if (v) out.push({ key, value: clip(key === 'date' || key === 'modified' ? pdfDate(v) : v), sensitive });
        }
        // Custom keys: whatever the producing program chose to record.
        // A Map in pdf.js 6, a plain object in older releases.
        const custom = info.Custom instanceof Map ? [...info.Custom] : Object.entries(info.Custom || {});
        for (const [k, v] of custom) {
            const value = v && typeof v === 'object' ? v.name : v;
            if (value != null && String(value).trim()) out.push({ key: 'custom', value: clip(`${k}: ${value}`), sensitive: true });
        }
        if (metadata) {
            const author = metadata.get('dc:creator');
            const tool = metadata.get('xmp:creatortool');
            if (author) out.push({ key: 'author', value: clip(Array.isArray(author) ? author.join(', ') : author), sensitive: true });
            if (tool) out.push({ key: 'software', value: clip(tool), sensitive: false });
            out.push({ key: 'xmp', value: '', sensitive: false });
        }
        return out;
    }

    /**
     * What the file carries, as a list of entries (see above, duplicates
     * removed), or null for a format this does not read. An empty list means
     * nothing was found. A PDF is read only through pdf, the document pdf.js
     * already opened for the preview.
     */
    async function inspect(blob, pdf = null) {
        return (await inspectKind(blob, pdf)).list;
    }

    /** inspect(), with the format it found: {kind, list}. */
    async function inspectKind(blob, pdf = null) {
        const kind = kindOf(new Uint8Array(await blob.slice(0, 12).arrayBuffer()));
        let list = null;
        if (kind === 'iso') {
            list = await inspectIso(blob);
            // Audio and video are cleaned differently (see stripIso()).
            const found = await readMoov(blob);
            if (found && isoIsAudio(found)) return finish('m4a', list);
        } else if (kind === 'pdf') {
            list = pdf ? await inspectPdf(pdf) : null;
        } else if (kind === 'mp3') {
            list = await inspectMp3(blob);
        } else if (kind === 'flac') {
            list = await inspectFlac(blob);
        } else if (kind) {
            const b = new Uint8Array(await blob.arrayBuffer());
            list = { jpeg: inspectJpeg, png: inspectPng, webp: inspectWebp, gif: inspectGif }[kind](b);
        }
        return finish(kind, list);
    }

    /** {kind, list} with duplicate entries removed. */
    function finish(kind, list) {
        if (!list) return { kind, list: null };
        const seen = new Set();
        return { kind, list: list.filter(e => {
            const id = e.key + '\u0000' + e.value;
            if (seen.has(id)) return false;
            seen.add(id);
            return true;
        }) };
    }

    // ── Preview panel ─────────────────────────────────────────────────────────
    // Field labels are meta_field_<key>, built here, so the directive ships them.
    // @lang-prefix meta_field_
    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;

    // What the upload does with each format, said under the list.
    const NOTE = kind =>
        kind === 'jpeg' || kind === 'png' || kind === 'webp' ? t('meta_note_cleaned')
            : kind === 'iso' ? t('meta_note_video')
                : kind === 'm4a' ? t('meta_note_location')
                    : t('meta_note_kept');

    /**
     * A collapsible "Metadata" box at the top of a dashboard preview of an
     * image, video, audio file or PDF (pdf: the preview's pdf.js document):
     * what the file carries, and under it what the upload does with that
     * format. A format this script does not read says so rather than
     * looking clean. Open from the start when something can identify a
     * place or a person. This script is loaded by the dashboard only, so
     * share visitors never see it (preview.js calls it if present).
     */
    async function renderPanel(body, blob, pdf = null, mime = '') {
        const media = (/^(image|video|audio)\//.test(mime) && mime !== 'image/svg+xml') || mime === 'application/pdf';
        if (!media || !body) return;
        let found;
        try {
            found = await inspectKind(blob, pdf);
        } catch {
            found = { kind: null, list: null };
        }
        const { kind, list } = found;
        const { esc, fmt, icon } = AESBinHelper;
        const sensitive = !!list && list.some(e => e.sensitive);
        // Text first, icon after it, as in every folded section: the arrow
        // is on the left. A no-break space joins the two, so a line that
        // wraps takes the last word down with the icon, never the icon alone.
        const head = !list
            ? esc(t('meta_panel_unchecked')) + '&nbsp;' + icon('info', 'ic-muted')
            : !list.length
                ? esc(t('meta_panel_none')) + '&nbsp;' + icon('circle-check', 'ic-ok')
                : esc(fmt(list.length === 1
                    ? (sensitive ? t('meta_panel_sensitive_one') : t('meta_panel_found_one'))
                    : (sensitive ? t('meta_panel_sensitive') : t('meta_panel_found')), { count: list.length }))
                    + '&nbsp;' + (sensitive ? icon('triangle-alert', 'ic-warn') : icon('info'));
        const rows = (list || []).map(e =>
            `<dt${e.sensitive ? ' class="meta-sensitive"' : ''}>${esc(t('meta_field_' + e.key))}</dt>` +
            `<dd>${e.value ? esc(e.value) : '—'}</dd>`).join('');
        const note = list ? NOTE(kind) : t('meta_note_unchecked');
        const box = document.createElement('details');
        box.className = 'meta-panel';
        box.innerHTML = `<summary>${head}</summary>`
            + (rows ? `<dl class="meta-list">${rows}</dl>` : '')
            + `<p class="meta-note">${esc(note)}</p>`;
        box.open = sensitive;
        body.prepend(box);
    }

    window.AESBinStripMeta = Object.freeze({ strip, inspect, renderPanel });
})();
