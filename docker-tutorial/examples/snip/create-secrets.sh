#!/bin/sh
# Generates the secrets that compose.yaml expects. Run once per machine; never commit the results.
set -eu
cd "$(dirname "$0")"
mkdir -p secrets
chmod 700 secrets                 # only you can list or open the directory on the host…
if [ ! -f secrets/db_password.txt ]; then
  openssl rand -hex 24 > secrets/db_password.txt
  echo "created secrets/db_password.txt"
fi
chmod 644 secrets/db_password.txt # …but the file must be readable by the containers' users (UID 65532, 70)
