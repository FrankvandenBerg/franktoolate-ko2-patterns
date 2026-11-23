# K.O. II Patterns

A web tool that connects to a Teenage Engineering EP-133 K.O. II over Web MIDI (Chrome/Edge), reads all projects, and lets you browse, play and rearrange the recorded patterns.

## Features

- **Browse**: all projects, patterns per group (A–D) and scenes, with a piano roll per pattern showing which pads play and their sample names.
- **Play**: plays a pattern or a whole scene on the K.O. II by sending MIDI notes at the project tempo.
- **Move**: swap or move patterns within a project (drag and drop). Within a group, scenes follow the moved pattern.
- **Safe writing**: before writing, the tool downloads a backup of the project and checks that it has not changed on the device; after writing, it reads the project back to verify it. If a write fails halfway, the backup is restored.
- **Offline**: open project backups (`.tar`) without a device.

## Running locally

Double-click `start.command`, or:

```bash
cd web && python3 -m http.server 8133 --bind 127.0.0.1
```

Then open http://localhost:8133 in Chrome, click **Connect K.O. II** and allow MIDI access.

## Deployment

The tool runs behind a sign-in on a PHP 8.3 + MySQL host (cPanel works). `server/` contains a small PHP front controller with passwordless accounts: people sign up with their email, confirm it via an emailed link, and then create a passkey (WebAuthn, verified with OpenSSL; no extra PHP extensions needed). Sign-in is with the passkey; an emailed one-time link is the fallback for a new device. Admins can invite, disable and delete accounts.

Layout on the server:

- an app folder outside the web root with `lib/`, `app/` (the web tool) and `config.local.php` (secrets, not in git; copy `server/config.example.php`);
- the document root of the (sub)domain with `index.php` and `.htaccess` from `server/public/`.

`deploy.sh` uploads both over SSH; it reads the connection details from a local `.deploy.env` (not in git). On first run, open `/setup`, enter the `setup_token` from `config.local.php`, and create the admin account and its passkey. Others sign up at `/register` or get an invitation from **Users**.

Web MIDI needs HTTPS and works in Chrome and Edge; Safari does not support Web MIDI.

## Contents

- `web/index.html`, `web/ko2lib.js`: the web tool (sysex protocol, tar handling, pattern/scene parsing, playback).
- `server/`: PHP sign-in layer and web-root entry point; `deploy.sh`: upload to the host.
- `ko2fs.py`, `dump_all.py`, `analyze.py`, `sndmeta.py`: Python version of the same device access (needs `mido` and `python-rtmidi`).

## Notes

The K.O. II sysex protocol is not officially documented. Use at your own risk and keep backups.

## Credits and third-party code

- **[ep133-export-to-daw](https://github.com/phones24/ep133-export-to-daw)** by **phones24 (Yura V)**, GNU AGPL v3.0. The device protocol layer (TE sysex framing, 7-bit packing, greet, file init, listing and downloading files) in `web/ko2lib.js` and `ko2fs.py` is adapted from this project. Everything built on top of it (writing and restoring projects, metadata, sounds, playback, editing, song timing, the web app and server) was added in this repository. Many thanks to phones24 for working out and publishing the protocol.
- Writing a project follows the restore flow used by Teenage Engineering's own web tool; no Teenage Engineering code is included.
- Fonts: [Archivo](https://github.com/Omnibus-Type/Archivo) and [IBM Plex Mono](https://github.com/IBM/plex), SIL Open Font License 1.1 (`server/lib/fonts/`).

See `CREDITS.md` for details. This project is licensed under the GNU AGPL v3.0 or later, the same licence as ep133-export-to-daw.

## License

AGPL-3.0, see `LICENSE`.
