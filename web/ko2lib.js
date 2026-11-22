// KO2 / EP-133 read-only access over Web MIDI sysex + project parsing.
// Based on the protocol implementation in github.com/phones24/ep133-export-to-daw (AGPL-3.0).
// SPDX-License-Identifier: AGPL-3.0-or-later
(function (root) {
  const TE = [0x00, 0x20, 0x76];
  const CMD_GREET = 1, CMD_FILE = 5;

  function pack7(data) {
    const out = [];
    for (let i = 0; i < data.length; i += 7) {
      const grp = data.slice(i, i + 7);
      let msb = 0;
      grp.forEach((b, j) => { msb |= (b >> 7) << j; });
      out.push(msb, ...Array.from(grp, b => b & 0x7f));
    }
    return out;
  }

  function unpack7(data) {
    const out = [];
    for (let i = 0; i < data.length; i += 8) {
      const msb = data[i];
      for (let j = 0; j < 7 && i + 1 + j < data.length; j++) out.push(data[i + 1 + j] | (((msb >> j) & 1) << 7));
    }
    return new Uint8Array(out);
  }

  const be16 = (b, o) => (b[o] << 8) | b[o + 1];
  const be32 = (b, o) => ((b[o] << 24) | (b[o + 1] << 16) | (b[o + 2] << 8) | b[o + 3]) >>> 0;
  const cstr = (b, o) => { let e = o; while (e < b.length && b[e] !== 0) e++; return [new TextDecoder().decode(b.subarray(o, e)), e]; };

  class KO2 {
    constructor(input, output) {
      this.input = input; this.output = output;
      this.rid = Math.floor(Math.random() * 4000);
      this.pending = new Map();
      this.onMidi = (ev) => this._handle(ev.data);
      input.addEventListener('midimessage', this.onMidi);
    }

    static async connect() {
      if (!navigator.requestMIDIAccess) throw new Error('This browser does not support Web MIDI. Use Chrome or Edge.');
      const access = await navigator.requestMIDIAccess({ sysex: true });
      const find = (ports) => Array.from(ports.values()).find(p => /EP-13/i.test(p.name || ''));
      const input = find(access.inputs), output = find(access.outputs);
      if (!input || !output) throw new Error('No EP-133 found. Is the K.O. II connected over USB and switched on?');
      await input.open(); await output.open();
      return new KO2(input, output);
    }

    close() { this.input.removeEventListener('midimessage', this.onMidi); }

    _handle(d) {
      if (d[0] !== 0xf0 || d[1] !== TE[0] || d[2] !== TE[1] || d[3] !== TE[2] || d[5] !== 0x40) return;
      if (d[6] & 0x40) return; // a request, not a response
      const rid = ((d[6] & 0x1f) << 7) | (d[7] & 0x7f);
      const p = this.pending.get(rid);
      if (!p) return;
      this.pending.delete(rid); clearTimeout(p.t);
      const status = d[9];
      if (status !== 0) p.reject(new Error(`K.O. II returned error status ${status}`));
      else p.resolve(unpack7(d.subarray(10, d.length - 1)));
    }

    req(cmd, payload = [], timeout = 5000) {
      this.rid = (this.rid + 1) % 4096;
      const rid = this.rid;
      const msg = [0xf0, ...TE, 0, 0x40, 0x40 | 0x20 | ((rid >> 7) & 0x1f), rid & 0x7f, cmd, ...pack7(Array.from(payload)), 0xf7];
      return new Promise((resolve, reject) => {
        const t = setTimeout(() => { this.pending.delete(rid); reject(new Error('No response from the K.O. II')); }, timeout);
        this.pending.set(rid, { resolve, reject, t });
        this.output.send(msg);
      });
    }

    async greet() {
      const r = await this.req(CMD_GREET);
      const info = {};
      new TextDecoder().decode(r).split(';').forEach(kv => { const [k, v] = kv.split(':'); if (k && v) info[k] = v; });
      return info;
    }

    async fsInit() {
      const r = await this.req(CMD_FILE, [1, 0, 0x00, 0x40, 0x00, 0x00]);
      this.chunk = r.length >= 5 ? be32(r, 1) : 512;
      return this.chunk;
    }

    // Upload a file; for a project archive use isDir=true with the project's node id (as TE's restore does).
    async put(data, name, parentId, fileId = 0, isDir = false, onProgress) {
      const flags = 4 | (isDir ? 2 : 1);
      const nameBytes = Array.from(new TextEncoder().encode(name).subarray(0, 54));
      const size = data.length;
      const init = [2, 0, flags, fileId >> 8, fileId & 255, parentId >> 8, parentId & 255,
        (size >>> 24) & 255, (size >>> 16) & 255, (size >>> 8) & 255, size & 255, ...nameBytes, 0];
      // The K.O. II can be busy for a while (e.g. loading samples for pads): use long timeouts
      // and retry a page that comes back with an error status.
      const send = async (payload) => {
        for (let attempt = 1; ; attempt++) {
          try { return await this.req(CMD_FILE, payload, 60000); }
          catch (e) {
            if (attempt >= 6 || /No response/.test(e.message)) throw e;
            await new Promise(r => setTimeout(r, 500 * attempt));
          }
        }
      };
      const r = await send(init);
      const a = ((this.chunk || 512) - 6) - 1 - 11;
      const maxp = a - Math.floor(a / 8);
      let page = 0;
      for (let off = 0; off < size; off += maxp, page++) {
        await send([2, 1, page >> 8, page & 255, ...data.subarray(off, off + maxp)]);
        onProgress && onProgress(Math.min(off + maxp, size), size);
      }
      await send([2, 1, page >> 8, page & 255]);
      return be16(r, 0);
    }

    async ls(node) {
      const out = [];
      for (let page = 0; ; page++) {
        const r = await this.req(CMD_FILE, [4, page >> 8, page & 255, node >> 8, node & 255]);
        if (r.length <= 2) break;
        const p = r.subarray(2);
        let off = 0;
        while (off + 7 <= p.length) {
          const nodeId = be16(p, off), flags = p[off + 2], size = be32(p, off + 3);
          const [name, end] = cstr(p, off + 7);
          out.push({ node: nodeId, flags, size, name, dir: !(flags & 1) });
          off = end + 1;
        }
      }
      return out;
    }

    async get(node, onProgress) {
      const r = await this.req(CMD_FILE, [3, 0, node >> 8, node & 255, 0, 0, 0, 0]);
      const size = be32(r, 3);
      const buf = new Uint8Array(size);
      let got = 0;
      for (let page = 0; got < size; page = (page + 1) & 0xffff) {
        const c = await this.req(CMD_FILE, [3, 1, page >> 8, page & 255]);
        if (be16(c, 0) !== page) throw new Error('Unexpected page while downloading');
        if (c.length <= 2) break;
        const data = c.subarray(2, 2 + Math.min(c.length - 2, size - got));
        buf.set(data, got); got += data.length;
        onProgress && onProgress(got, size);
      }
      return buf.subarray(0, got);
    }

    async setMetadata(node, obj) {
      const json = Array.from(new TextEncoder().encode(JSON.stringify(obj)));
      await this.req(CMD_FILE, [7, 1, node >> 8, node & 255, ...json, 0], 30000);
    }

    async metadata(node) {
      let s = '';
      for (let page = 0; ; page++) {
        const r = await this.req(CMD_FILE, [7, 2, node >> 8, node & 255, page >> 8, page & 255]);
        if (r.length <= 2) break;
        const chunk = r.subarray(2);
        s += cstr(chunk, 0)[0];
        if (chunk[chunk.length - 1] === 0) break;
      }
      try { return JSON.parse(s); } catch { return {}; }
    }
  }

  function untar(buf) {
    const files = {};
    for (let o = 0; o + 512 <= buf.length;) {
      const h = buf.subarray(o, o + 512);
      if (h.every(b => b === 0)) break;
      let name = cstr(h, 0)[0];
      const prefix = cstr(h, 345)[0];
      if (prefix && new TextDecoder().decode(h.subarray(257, 262)) === 'ustar') name = prefix + '/' + name;
      const size = parseInt(cstr(h, 124)[0].trim() || '0', 8);
      const type = String.fromCharCode(h[156] || 48);
      if (type === '0' || type === '\0') files[name.replace(/^\.?\//, '')] = buf.slice(o + 512, o + 512 + size);
      o += 512 + Math.ceil(size / 512) * 512;
    }
    return files;
  }

  // ---- tar editing (keeps the device's own minimal header style) ----
  function tarEntries(buf) {
    const out = [];
    for (let o = 0; o + 512 <= buf.length;) {
      const h = buf.slice(o, o + 512);
      if (h.every(b => b === 0)) break;
      const name = cstr(h, 0)[0];
      const size = parseInt(cstr(h, 124)[0].trim() || '0', 8);
      out.push({ header: h, name, data: buf.slice(o + 512, o + 512 + size) });
      o += 512 + Math.ceil(size / 512) * 512;
    }
    return out;
  }

  function tarHeader(template, name, size) {
    const h = new Uint8Array(template);
    h.fill(0, 0, 100);
    h.set(new TextEncoder().encode(name).subarray(0, 99), 0);
    // the device writes the size as plain octal ("164\0"), and leaves it empty for directories
    const emptySize = template[124] === 0 && size === 0;
    h.fill(0, 124, 136);
    if (!emptySize) h.set(new TextEncoder().encode(size.toString(8)), 124);
    h.fill(32, 148, 156);
    const sum = h.reduce((a, b) => a + b, 0);
    // device style: plain octal, NUL, padded with spaces ("3722\0   ")
    const cs = sum.toString(8);
    h.fill(32, 148, 156);
    h.set(new TextEncoder().encode(cs), 148);
    h[148 + cs.length] = 0;
    return h;
  }

  function buildTar(entries) {
    const total = entries.reduce((n, e) => n + 512 + Math.ceil(e.data.length / 512) * 512, 0) + 1024;
    const out = new Uint8Array(total);
    let o = 0;
    for (const e of entries) {
      out.set(tarHeader(e.header, e.name, e.data.length), o); o += 512;
      out.set(e.data, o); o += Math.ceil(e.data.length / 512) * 512;
    }
    return out;
  }

  // Move or swap a pattern slot inside a project archive.
  // from/to: {g:'A'..'D', n:1..99}. If the target exists the two are swapped.
  // Scenes that referenced the moved pattern(s) follow along when the group stays the same.
  function movePattern(tarBuf, from, to) {
    const entries = tarEntries(tarBuf);
    const key = p => `patterns/${p.g.toLowerCase()}${String(p.n).padStart(2, '0')}`;
    const kf = key(from), kt = key(to);
    if (kf === kt) return { tar: tarBuf, changed: false };
    const ef = entries.find(e => e.name === kf), et = entries.find(e => e.name === kt);
    if (!ef) throw new Error(`Pattern ${from.g}${String(from.n).padStart(2, '0')} does not exist`);
    ef.name = kt;
    if (et) et.name = kf;
    const swapped = !!et;
    if (from.g === to.g) {
      const sc = entries.find(e => e.name === 'scenes');
      if (sc) {
        const gi = 'ABCD'.indexOf(from.g);
        for (let i = 0; i < 99; i++) {
          const o = 7 + i * 6 + gi;
          if (o >= sc.data.length) break;
          if (sc.data[o] === from.n) sc.data[o] = to.n;
          else if (swapped && sc.data[o] === to.n) sc.data[o] = from.n;
        }
      }
    }
    // keep the device's ordering: dirs first in their place, pattern files sorted under 'patterns'
    const pats = entries.filter(e => e.name.startsWith('patterns/')).sort((a, b) => a.name.localeCompare(b.name));
    const rest = entries.filter(e => !e.name.startsWith('patterns/'));
    const idx = rest.findIndex(e => e.name === 'patterns');
    const ordered = idx >= 0 ? [...rest.slice(0, idx + 1), ...pats, ...rest.slice(idx + 1)] : [...rest, ...pats];
    return { tar: buildTar(ordered), changed: true, swapped };
  }

  const patKey = (g, n) => `patterns/${g.toLowerCase()}${String(n).padStart(2, '0')}`;

  // Delete pattern files. Scenes keep pointing at the number; that group is then silent there.
  function deletePatterns(tarBuf, list) {
    const keys = new Set(list.map(p => patKey(p.g, p.n)));
    const entries = tarEntries(tarBuf).filter(e => !keys.has(e.name));
    return buildTar(entries);
  }

  // Clear scenes (1–99). Song positions that used them are removed; the active scene moves to one that remains.
  // With withPatterns, pattern files used only by the deleted scenes are deleted too.
  function deleteScenes(tarBuf, sceneNos, withPatterns) {
    const entries = tarEntries(tarBuf);
    const sc = entries.find(e => e.name === 'scenes');
    if (!sc) throw new Error('This project has no scenes file.');
    const d = new Uint8Array(sc.data);
    const del = new Set(sceneNos);
    const chunk = n => d.subarray(7 + (n - 1) * 6, 7 + (n - 1) * 6 + 4);
    const used = n => { const c = chunk(n); return c[0] || c[1] || c[2] || c[3]; };
    const doomed = [];
    if (withPatterns) {
      for (const n of del) {
        if (!used(n)) continue;
        chunk(n).forEach((pn, gi) => {
          if (!pn) return;
          let shared = false;
          for (let m = 1; m <= 99; m++) if (!del.has(m) && used(m) && chunk(m)[gi] === pn) shared = true;
          if (!shared) doomed.push({ g: 'ABCD'[gi], n: pn });
        });
      }
    }
    for (const n of del) chunk(n).fill(0);
    const remaining = []; for (let m = 1; m <= 99; m++) if (used(m)) remaining.push(m);
    if (d.length >= 712) {
      const song = []; for (let i = 0; i < d[612]; i++) song.push(d[613 + i]);
      let kept = song.filter(n => !del.has(n));
      if (!kept.length) kept = [remaining[0] || 1];
      d[612] = kept.length; d.fill(0, 613, 712); kept.forEach((n, i) => { d[613 + i] = n; });
      if (del.has(d[604])) d[604] = remaining[0] || 1;
    }
    sc.data = d;
    const keys = new Set(doomed.map(p => patKey(p.g, p.n)));
    return { tar: buildTar(entries.filter(e => !keys.has(e.name))), deletedPatterns: doomed };
  }

  // Point a scene's group (A–D) at another pattern number (0 = no pattern).
  function setScenePattern(tarBuf, sceneNo, group, n) {
    const entries = tarEntries(tarBuf);
    const sc = entries.find(e => e.name === 'scenes');
    if (!sc) throw new Error('This project has no scenes file.');
    if (sceneNo < 1 || sceneNo > 99 || n < 0 || n > 99) throw new Error('Scene and pattern numbers run from 1 to 99.');
    const d = new Uint8Array(sc.data);
    d[7 + (sceneNo - 1) * 6 + 'ABCD'.indexOf(group)] = n;
    sc.data = d;
    return buildTar(entries);
  }

  // Replace the song list (scene number per position, 1–99 positions) in a project archive.
  function setSong(tarBuf, positions) {
    const entries = tarEntries(tarBuf);
    const sc = entries.find(e => e.name === 'scenes');
    if (!sc || sc.data.length < 712) throw new Error('This project has no song data (it may come from an older OS). Make a song on the K.O. II first.');
    if (positions.length < 1 || positions.length > 99) throw new Error('A song has 1 to 99 positions.');
    if (positions.some(n => !Number.isInteger(n) || n < 1 || n > 99)) throw new Error('Scene numbers run from 1 to 99.');
    const d = new Uint8Array(sc.data);
    d[612] = positions.length;
    d.fill(0, 613, 712);
    positions.forEach((n, i) => { d[613 + i] = n; });
    sc.data = d;
    return buildTar(entries);
  }

  // pattern events: 8 bytes = pos(LE16), pad*8, note, velocity, duration(LE16), flag
  function parseProject(id, files) {
    const f32 = (b, o) => new DataView(b.buffer, b.byteOffset + o, 4).getFloat32(0, true);
    const bpm = files.settings ? Math.round(f32(files.settings, 4) * 100) / 100 : null;
    const pads = {};
    for (const [n, d] of Object.entries(files)) {
      const m = n.match(/^pads\/([abcd])\/p(\d+)$/);
      if (m && d.length > 3) pads[m[1] + Number(m[2])] = (d[2] << 8) + d[1];
    }
    const pats = [];
    for (const [n, d] of Object.entries(files)) {
      const m = n.match(/^patterns\/([abcd])(\d+)$/);
      if (!m) continue;
      const ev = [];
      for (let i = 4; i + 8 <= d.length; i += 8) {
        if (d[i + 2] % 8) continue;
        ev.push([d[i + 2] / 8, d[i] | (d[i + 1] << 8), d[i + 5] | (d[i + 6] << 8), d[i + 4], d[i + 3]]);
      }
      pats.push({ g: m[1].toUpperCase(), n: Number(m[2]), bars: d[1], notes: ev.length, ev });
    }
    pats.sort((a, b) => a.g.localeCompare(b.g) || a.n - b.n);
    const scenes = [];
    const sc = files.scenes;
    if (sc) for (let i = 0; i < 99; i++) {
      const c = sc.subarray(7 + i * 6, 13 + i * 6);
      if (c.length < 6 || !(c[0] || c[1] || c[2] || c[3])) continue;
      scenes.push([i + 1, c[0], c[1], c[2], c[3]]);
    }
    // Song: byte 604 = active scene, byte 612 = number of song positions, 613.. = scene per position
    let song = [], activeScene = null;
    if (sc && sc.length >= 613) {
      activeScene = sc[604] || null;
      const n = Math.min(sc[612], 99, sc.length - 613);
      for (let i = 0; i < n; i++) song.push(sc[613 + i]);
    }
    return { p: id, bpm, pads, pats, scenes, song, activeScene, songEditable: !!(sc && sc.length >= 712) };
  }

  // Plays patterns on the device by sending timestamped MIDI notes.
  // Note map from the TE guide (14.2): group A = 36–47, B = 48–59, C = 60–71, D = 72–83, on channel 1.
  const NOTE_BASE = { A: 36, B: 48, C: 60, D: 72 };
  // Pad files p01–p12 (and pattern pad indexes 0–11) follow the pad grid 7 8 9 / 4 5 6 / 1 2 3 / . 0 ENTER,
  // while MIDI notes per group run . 0 ENTER 1 2 … 9. This maps a pad index to its note offset.
  // (Confirmed on the device: pressing pad "1" sends base + 3.)
  const PAD_NOTE_OFFSET = [9, 10, 11, 6, 7, 8, 3, 4, 5, 0, 1, 2];
  const PAD_LABELS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', 'ENTER'];
  const TICKS_PER_BAR = 384; // 96 ppqn, 4/4

  class Player {
    constructor(output) { this.output = output; this.timer = null; this.onTick = null; this.muted = new Set(); }

    // Mixer: silence a group (A–D) while playing. Only affects what the tool sends; nothing is stored on the device.
    setMuted(group, muted) {
      if (muted) {
        this.muted.add(group);
        const base = NOTE_BASE[group];
        for (let n = base; n < base + 12; n++) this.output.send([0x80, n, 0]);
      } else this.muted.delete(group);
    }

    get playing() { return this.timer !== null; }

    play(patterns, bpm) { return this.playSegments([patterns], bpm); }

    // Plays a list of segments one after another and loops the whole list.
    // A segment is a set of patterns played together (a scene); it lasts as long as its longest pattern.
    playSegments(segments, bpm) {
      this.stop();
      const events = [];
      this.segmentStarts = [];
      let segStart = 0;
      for (const patterns of segments) {
        const segTicks = Math.max(1, ...patterns.map(p => p.bars || 1)) * TICKS_PER_BAR;
        this.segmentStarts.push(segStart);
        for (const p of patterns) {
          const len = Math.max(1, p.bars) * TICKS_PER_BAR;
          for (let off = 0; off < segTicks; off += len) {
            for (const [pad, pos, dur, vel] of p.ev) {
              if (pos >= len) continue;
              events.push({ t: segStart + off + pos, g: p.g, note: NOTE_BASE[p.g] + (PAD_NOTE_OFFSET[pad] ?? pad), vel: Math.max(1, Math.min(127, vel)), dur: Math.max(dur, 12) });
            }
          }
        }
        segStart += segTicks;
      }
      const loopTicks = segStart;
      events.sort((a, b) => a.t - b.t);
      this.msPerTick = 60000 / ((bpm || 120) * 96);
      this.loopTicks = loopTicks;
      this.start = performance.now() + 80;
      this.events = events;
      this.loop = 0; this.idx = 0;
      const LOOKAHEAD = 120;
      const pump = () => {
        const horizon = performance.now() + LOOKAHEAD;
        if (!events.length) return;
        for (;;) {
          const e = events[this.idx];
          const at = this.start + (this.loop * loopTicks + e.t) * this.msPerTick;
          if (at > horizon) break;
          if (!this.muted.has(e.g)) {
            this.output.send([0x90, e.note, e.vel], at);
            this.output.send([0x80, e.note, 0], at + e.dur * this.msPerTick);
          }
          if (++this.idx >= events.length) { this.idx = 0; this.loop++; }
        }
      };
      pump();
      this.timer = setInterval(pump, 25);
      const frame = () => {
        if (!this.playing) return;
        const ticks = ((performance.now() - this.start) / this.msPerTick) % loopTicks;
        const tk = Math.max(0, ticks);
        let seg = 0; while (seg + 1 < this.segmentStarts.length && this.segmentStarts[seg + 1] <= tk) seg++;
        this.onTick && this.onTick(tk / loopTicks, seg);
        this.raf = requestAnimationFrame(frame);
      };
      this.raf = requestAnimationFrame(frame);
    }

    stop() {
      if (this.timer !== null) { clearInterval(this.timer); this.timer = null; }
      cancelAnimationFrame(this.raf);
      if (this.output.clear) try { this.output.clear(); } catch {}
      for (const base of Object.values(NOTE_BASE)) for (let n = base; n < base + 12; n++) this.output.send([0x80, n, 0]);
      this.onTick && this.onTick(null);
    }
  }

  const api = { KO2, Player, untar, parseProject, pack7, unpack7, tarEntries, buildTar, movePattern, setSong, deletePatterns, deleteScenes, setScenePattern, PAD_LABELS, PAD_NOTE_OFFSET };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.KO2Lib = api;
})(typeof window !== 'undefined' ? window : globalThis);
