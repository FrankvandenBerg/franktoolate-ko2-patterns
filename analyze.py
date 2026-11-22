import os, sys, json, tarfile, struct
sys.path.insert(0, '.')
out = {}
used_sounds = set()
for p in [f'{i:02d}' for i in range(1, 10)]:
    t = tarfile.open(f'tars/P{p}.tar')
    f = {m.name: t.extractfile(m).read() for m in t.getmembers() if m.isfile()}
    bpm = round(struct.unpack('<f', f['settings'][4:8])[0], 2) if 'settings' in f else None
    pads = {}
    for n, d in f.items():
        if n.startswith('pads/') and len(d) > 3:
            g, pn = n.split('/')[1], int(n.split('/')[2][1:])
            sid = (d[2] << 8) + d[1]
            pads[f'{g}{pn}'] = sid
    sc = f.get('scenes', b'')
    scenes = []
    for i in range(99):
        c = sc[7 + i * 6: 13 + i * 6]
        if len(c) < 6 or not any(c[:4]): continue
        scenes.append({'scene': i + 1, 'a': c[0], 'b': c[1], 'c': c[2], 'd': c[3], 'ts': f'{c[4]}/{c[5]}'})
    pats = []
    for n, d in sorted(f.items()):
        if not n.startswith('patterns/'): continue
        g, num = n[9], int(n[10:])
        bars = d[1]
        notes = {}
        for i in range(4, len(d) - 7, 8):
            ch = d[i:i + 8]
            if ch[2] % 8: continue
            pad = ch[2] // 8
            notes.setdefault(pad, []).append(dict(pos=ch[0] | ch[1] << 8, note=ch[3], vel=ch[4], dur=ch[5] | ch[6] << 8))
        padsused = {f'{g}{k}': len(v) for k, v in sorted(notes.items())}
        for k in padsused:
            if pads.get(k): used_sounds.add(pads[k])
        pats.append({'group': g.upper(), 'num': num, 'bars': bars, 'notes': sum(padsused.values()), 'pads': padsused, 'events': {k: v for k, v in notes.items()}})
    out[p] = {'bpm': bpm, 'scenes': scenes, 'patterns': pats, 'pad_sounds': pads}
json.dump(out, open('analysis.json', 'w'))
json.dump(sorted(used_sounds), open('used_sounds.json', 'w'))
for p, v in out.items():
    nonempty = [x for x in v['patterns'] if x['notes']]
    print(p, 'bpm', v['bpm'], 'scenes', len(v['scenes']), 'patterns', len(v['patterns']), 'nonempty', len(nonempty),
          ' '.join(f"{x['group']}{x['num']:02d}({x['bars']}b,{x['notes']}n)" for x in nonempty))
print('used sounds', len(used_sounds))
