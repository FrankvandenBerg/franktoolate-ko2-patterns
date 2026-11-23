# Credits

## ep133-export-to-daw

- Author: phones24 (Yura V), https://github.com/phones24
- Project: https://github.com/phones24/ep133-export-to-daw
- Licence: GNU Affero General Public License v3.0

The low-level protocol for talking to the EP-133 K.O. II over MIDI sysex was adapted from this project:

- Teenage Engineering sysex message framing and request IDs
- 7-bit packing and unpacking of sysex data
- the greet command and file-system init
- listing directories and downloading files (including a project as a tar archive)

In this repository that code lives in `web/ko2lib.js` (the `KO2` class: `req`, `greet`, `fsInit`, `ls`, `get`, and `pack7`/`unpack7`) and in its Python port `ko2fs.py`. It was modified and extended by Frank van den Berg in 2026. All other parts were written for this project: uploading and restoring projects and sounds, metadata, deleting files, device playback commands, project, scene and song parsing and editing, the song timing model, playback, the web app and the PHP server.

Both projects use the GNU AGPL v3.0, so this repository is licensed under the AGPL v3.0 or later, and its full source code is public at https://github.com/FrankvandenBerg/franktoolate-ko2-patterns.

## Teenage Engineering

Writing a project follows the restore flow used by Teenage Engineering's own web tool. No Teenage Engineering code, firmware or sounds are included. "Teenage Engineering", "EP-133" and "K.O. II" are trademarks of Teenage Engineering AB.

## Fonts

- Archivo, by Omnibus-Type, SIL Open Font License 1.1 (`server/lib/fonts/OFL-Archivo.txt`)
- IBM Plex Mono, by IBM, SIL Open Font License 1.1 (`server/lib/fonts/OFL-IBM-Plex-Mono.txt`)
