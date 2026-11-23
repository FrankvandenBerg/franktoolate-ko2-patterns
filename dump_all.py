# SPDX-License-Identifier: AGPL-3.0-or-later
# Uses ko2fs.py (protocol adapted from phones24/ep133-export-to-daw, AGPL-3.0). See CREDITS.md.
import os, sys, json
sys.path.insert(0, '.')
from ko2fs import KO2
k = KO2(); k.fs_init()
os.makedirs('tars', exist_ok=True)
for p in k.ls(2000):
    d = k.get(p['node']); open(f"tars/P{p['name']}.tar",'wb').write(d); print(p['name'], len(d), flush=True)
snd = k.ls(1000); json.dump(snd, open('sounds_ls.json','w')); print('sounds', len(snd), snd[:5], flush=True)
os._exit(0)
