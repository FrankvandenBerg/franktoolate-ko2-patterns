import os, sys, json, struct
sys.path.insert(0, '.')
from ko2fs import KO2
k = KO2(); k.fs_init()
ids = json.load(open('used_sounds.json'))
nodes = {int(s['name'][:3]): s['node'] for s in json.load(open('sounds_ls.json')) if s['name'][:3].isdigit()}
res = {}
for sid in ids:
    n = nodes.get(sid)
    if n is None: continue
    s, page = '', 0
    while True:
        r = k.req(5, bytes([7, 2]) + struct.pack('>HH', n, page))
        if len(r) <= 2: break
        chunk = r[2:]; s += chunk.split(b'\0')[0].decode(errors='replace'); page += 1
        if chunk[-1:] == b'\0': break
    try: res[sid] = json.loads(s).get('name', '')
    except Exception: res[sid] = s[:60]
    print(sid, res[sid], flush=True)
old=json.load(open('sound_names.json')); old.update({str(k):v for k,v in res.items()}); json.dump(old, open('sound_names.json', 'w'))
os._exit(0)
