#!/bin/sh
# Zero-downtime update of Snip to another image (chapter 18). Also how you roll back.
#   ./deploy.sh ghcr.io/you/snip:2.0.1
# 0. If SIGNER is set in .env, refuse images that CI didn't sign (chapter 15).
# 1. Get the image and run its migrations (which must also work with the old code).
# 2. Start new API containers next to the old ones and wait until they're healthy.
# 3. Stop the old ones: they finish their in-flight requests, and nginx moves on.
# 4. Replace the worker, and save the image in .env so that later "up" commands use it.
set -eu
cd "$(dirname "$0")"
image=${1:?usage: ./deploy.sh <image>}
# Without COMPOSE_FILE, every command below would also merge compose.override.yaml (development).
grep -q '^COMPOSE_FILE=' .env 2>/dev/null || [ -n "${COMPOSE_FILE:-}" ] ||
  { echo "set COMPOSE_FILE in .env first (see .env.example)" >&2; exit 1; }
export SNIP_IMAGE="$image"     # overrides .env for every command below

signer=$(sed -n 's/^SIGNER=//p' .env 2>/dev/null || true)
if [ -n "$signer" ]; then
  cosign verify "$image" --certificate-identity-regexp "$signer" \
    --certificate-oidc-issuer https://token.actions.githubusercontent.com >/dev/null ||
    { echo "$image has no valid signature from $signer; not deploying" >&2; exit 1; }
  echo "signature verified"
fi

docker image inspect "$image" >/dev/null 2>&1 || docker pull "$image"
docker compose run --rm migrate

old=$(docker compose ps -q api)
count=$(echo "$old" | grep -c . || true)
[ "$count" -gt 0 ] || { echo "no running api containers; use docker compose up -d" >&2; exit 1; }
echo "starting $count new api containers next to the $count old ones"
docker compose up -d --no-deps --no-recreate --scale api=$((count * 2)) api
new=$(docker compose ps -q api | grep -v -F "$old")

for id in $new; do
  tries=0
  until [ "$(docker inspect -f '{{.State.Health.Status}}' "$id")" = healthy ]; do
    tries=$((tries + 1))
    if [ "$tries" -gt 60 ]; then
      echo "new container $id isn't healthy after 60 s; removing the new containers" >&2
      docker logs --tail 20 "$id" >&2
      docker rm -f $new >/dev/null
      exit 1
    fi
    sleep 1
  done
done

sleep 6                        # nginx re-resolves "api" every 5 s: let it find the new containers
echo "stopping the old api containers"
# One at a time, 6 s apart, so that nginx always has a container it's willing to use.
for id in $old; do
  docker stop "$id" >/dev/null # SIGTERM: it finishes its requests and exits (chapter 2)
  docker rm "$id" >/dev/null
  sleep 6
done
docker compose up -d --no-deps worker

if grep -q '^SNIP_IMAGE=' .env 2>/dev/null; then
  sed -i "s|^SNIP_IMAGE=.*|SNIP_IMAGE=$image|" .env
else
  echo "SNIP_IMAGE=$image" >> .env
fi
echo "deployed $image"
