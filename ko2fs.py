"""Access to the EP-133 file system over TE sysex.

SPDX-License-Identifier: AGPL-3.0-or-later
The protocol code (sysex framing, 7-bit packing, greet, file init, list, get) is a Python port of
ep133-export-to-daw by phones24 (Yura V), https://github.com/phones24/ep133-export-to-daw (AGPL-3.0).
Modified and extended (put/restore) by Frank van den Berg, 2026. See CREDITS.md."""
import mido, time, random, struct, sys, json, io, tarfile

PORT = 'EP-133'
TE = [0x00, 0x20, 0x76]
CMD_GREET, CMD_FILE = 1, 5


def pack7(data):
    out = bytearray()
    for i in range(0, len(data), 7):
        grp = data[i:i + 7]
        msb = 0
        for j, b in enumerate(grp):
            msb |= (b >> 7) << j
        out.append(msb)
        out.extend(b & 0x7F for b in grp)
    return bytes(out)


def unpack7(data):
    out = bytearray()
    for i in range(0, len(data), 8):
        msb = data[i]
        for j, b in enumerate(data[i + 1:i + 8]):
            out.append(b | (((msb >> j) & 1) << 7))
    return bytes(out)


class KO2:
    def __init__(self):
        self.i = mido.open_input(PORT)
        self.o = mido.open_output(PORT)
        self.rid = random.randint(0, 4000)
        time.sleep(0.2)
        list(self.i.iter_pending())

    def req(self, cmd, payload=b'', timeout=5):
        self.rid = (self.rid + 1) % 4096
        rid = self.rid
        msg = TE + [0, 0x40, 0x40 | 0x20 | ((rid >> 7) & 0x1F), rid & 0x7F, cmd] + list(pack7(bytes(payload)))
        self.o.send(mido.Message('sysex', data=msg))
        t = time.time()
        while time.time() - t < timeout:
            for m in self.i.iter_pending():
                if m.type != 'sysex':
                    continue
                d = m.data  # without F0/F7
                if list(d[:3]) != TE or d[4] != 0x40 or d[5] & 0x40:
                    continue
                r = ((d[5] & 0x1F) << 7) | (d[6] & 0x7F)
                if r != rid:
                    continue
                status = d[8]
                if status != 0:
                    raise RuntimeError(f'status {status} for cmd {cmd} payload {bytes(payload).hex()}')
                return unpack7(bytes(d[9:]))
            time.sleep(0.002)
        raise TimeoutError(f'no reply cmd {cmd}')

    def greet(self):
        return self.req(CMD_GREET).decode(errors='replace')

    def fs_init(self):
        r = self.req(CMD_FILE, bytes([1, 0]) + struct.pack('>I', 4 * 1024 * 1024))
        self.chunk = struct.unpack('>I', r[1:5])[0] if len(r) >= 5 else 0
        return self.chunk

    def put(self, data, name, parent, file_id=0, is_dir=False, timeout=60):
        """Upload a file (or a project tar when is_dir=True), like TE's restore."""
        flags = 4 | (2 if is_dir else 1)  # CAPABILITY_READ | DIR/FILE
        init = bytes([2, 0, flags]) + struct.pack('>HHI', file_id, parent, len(data)) + name.encode()[:54] + b'\0'
        def send(payload):
            for attempt in range(1, 7):
                try:
                    return self.req(CMD_FILE, payload, timeout)
                except RuntimeError:  # error status: device busy, retry the same page
                    if attempt == 6:
                        raise
                    time.sleep(0.5 * attempt)
        r = send(init)
        fid = struct.unpack('>H', r[:2])[0]
        o = 8 + 2 + 1
        a = (self.chunk - 6) - 1 - o
        maxp = a - a // 8
        page, off = 0, 0
        while off < len(data):
            send(bytes([2, 1]) + struct.pack('>H', page) + data[off:off + maxp])
            off += maxp; page += 1
        send(bytes([2, 1]) + struct.pack('>H', page))
        return fid

    def ls(self, node):
        out, page = [], 0
        while True:
            r = self.req(CMD_FILE, bytes([4]) + struct.pack('>HH', page, node))
            if len(r) < 2:
                break
            p = r[2:]
            if not p:
                break
            off = 0
            while off < len(p):
                nid, flags, size = struct.unpack('>HBI', p[off:off + 7])
                end = p.index(0, off + 7)
                out.append(dict(node=nid, flags=flags, size=size, name=p[off + 7:end].decode(errors='replace'),
                                dir=not (flags & 1)))
                off = end + 1
            page += 1
        return out

    def get(self, node):
        r = self.req(CMD_FILE, bytes([3, 0]) + struct.pack('>HI', node, 0))
        size = struct.unpack('>I', r[3:7])[0]
        buf, page = bytearray(), 0
        while len(buf) < size:
            r = self.req(CMD_FILE, bytes([3, 1]) + struct.pack('>H', page))
            if struct.unpack('>H', r[:2])[0] != page:
                raise RuntimeError('page mismatch')
            if len(r) <= 2:
                break
            buf += r[2:]
            page = (page + 1) & 0xFFFF
        return bytes(buf)


if __name__ == '__main__':
    k = KO2()
    print('GREET:', k.greet())
    k.fs_init()
    root = k.ls(0)
    print('ROOT:', root)
