#!/bin/sh
# Creates a self-signed certificate for https://localhost, for development only.
# Production uses a real certificate (Let's Encrypt; see chapter 18).
set -eu
cd "$(dirname "$0")"
mkdir -p certs
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=localhost \
  -addext subjectAltName=DNS:localhost,IP:127.0.0.1 \
  -keyout certs/privkey.pem -out certs/fullchain.pem 2>/dev/null
# nginx reads the key as root without DAC_OVERRIDE (chapter 16), which can't read another
# user's private files. Acceptable for a throwaway development key; never for a real one.
chmod 644 certs/privkey.pem
echo "created certs/fullchain.pem and certs/privkey.pem (valid 30 days)"
