#!/bin/zsh
# Start the K.O. II web tool on http://localhost:8133 and open it in Chrome.
cd "$(dirname "$0")/web"
(sleep 1; open -a "Google Chrome" "http://localhost:8133") &
python3 -m http.server 8133 --bind 127.0.0.1
