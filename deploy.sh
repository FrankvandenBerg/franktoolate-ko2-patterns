#!/bin/zsh
# Deploy to the hosting account over SSH. Connection details live in .deploy.env (not in git):
#   DEPLOY_SSH="ssh -p <port> -i <key> <user>@<host>"
#   DEPLOY_APP_DIR=kostudio            # outside the web root
#   DEPLOY_DOCROOT=kostudio.example.com # document root of the (sub)domain
set -euo pipefail
cd "$(dirname "$0")"
source ./.deploy.env
B=$(mktemp -d)
mkdir -p "$B/app"
cp -r server/lib "$B/lib"
mkdir -p "$B/app/ko2" && cp web/index.html web/ko2lib.js "$B/app/ko2/"
rsync -az --delete --exclude config.local.php -e "${DEPLOY_SSH% *}" "$B/" "${DEPLOY_SSH##* }:$DEPLOY_APP_DIR/"
rsync -az -e "${DEPLOY_SSH% *}" server/public/index.php "${DEPLOY_SSH##* }:$DEPLOY_DOCROOT/index.php"
# keep the host's cPanel-generated block at the top of .htaccess, replace the rest with ours
${=DEPLOY_SSH} "f=~/$DEPLOY_DOCROOT/.htaccess; sed -n '/# BEGIN cPanel-generated/,/# END cPanel-generated/p' \$f 2>/dev/null > \$f.new; echo >> \$f.new; cat >> \$f.new" < server/public/.htaccess
${=DEPLOY_SSH} "mv ~/$DEPLOY_DOCROOT/.htaccess.new ~/$DEPLOY_DOCROOT/.htaccess; rm -f ~/$DEPLOY_APP_DIR/source.zip"
rm -rf "$B"
echo "Deployed."
