# Chapter 15 — Registries and distribution

## What you'll learn

- What a registry stores, and the HTTP API every registry speaks
- The common registries: Docker Hub, GitHub Container Registry, cloud registries, self-hosted
- Logging in safely: access tokens, `config.json` and credential helpers
- Running your own registry with `registry:3`: authentication, deletion and garbage collection
- Deploying by digest, and promoting an image between environments without rebuilding it
- Retention policies, Docker Hub pull limits and registry mirrors
- Moving images without a registry: `docker save`, `docker load` and OCI archives
- Signing images with cosign and verifying them before deployment

## What a registry is

A **registry** is an HTTP service that stores images and hands them out. Every registry, from Docker Hub to the one
you run yourself, implements the same **OCI Distribution Specification**, so every client (`docker`, `containerd`,
Kubernetes, `crane`, `skopeo`) works with every registry.

A registry stores three kinds of things, all addressed by their SHA-256 digest (chapter 3):

- **Blobs**: layers (compressed tar archives) and image configurations.
- **Manifests**: JSON documents listing a config and its layers. An **index** is a manifest of manifests, one per
  platform, plus attestations.
- **Tags**: names in a repository that point to a manifest digest, and can be moved.

You can talk to a registry with `curl`. With the local registry from chapter 14 on port 5000:

```bash
curl -s localhost:5000/v2/_catalog
curl -s localhost:5000/v2/snip/tags/list
```

```text
{"repositories":["snip"]}
{"name":"snip","tags":["1.7","1.7.0","buildcache","sha256-afe1b952357a…","test"]}
```

A manifest, by tag. The `Accept` header says which formats the client understands:

```bash
curl -sI -H 'Accept: application/vnd.oci.image.index.v1+json' localhost:5000/v2/snip/manifests/1.7.0
```

```text
Content-Length: 1611
Content-Type: application/vnd.oci.image.index.v1+json
Docker-Content-Digest: sha256:afe1b952357a462af7ee56ad6fe4214e64c26f48958a9cd048e1f60de6816122
```

`docker pull snip:1.7.0` does exactly this: resolve the tag to a digest, fetch the index, pick the manifest for its
platform, fetch the config, then download each layer it doesn't already have:

```text
GET /v2/snip/manifests/1.7.0          tag  → index (digest sha256:afe1…)
GET /v2/snip/manifests/sha256:6511…   index → manifest for linux/amd64
GET /v2/snip/blobs/sha256:<config>    the image configuration
GET /v2/snip/blobs/sha256:<layer>     each layer not already present locally
```

Because everything is content-addressed, a layer shared by a hundred images is stored once and downloaded once, and a
download can be verified by hashing it. A push is the same dance in reverse, skipping blobs the registry already has.

The repository name's first component decides where an image lives (chapter 3):

```text
  snip:1.7                         → docker.io/library/snip:1.7   (Docker Hub, official images namespace)
  you/snip:1.7                     → docker.io/you/snip:1.7       (Docker Hub, your namespace)
  ghcr.io/you/snip:1.7             → GitHub Container Registry
  localhost:5000/snip:1.7          → a registry on this machine
  123456789012.dkr.ecr.eu-west-1.amazonaws.com/snip:1.7   → Amazon ECR
```

## Choosing a registry

| Registry | Good for | Notes |
|---|---|---|
| **Docker Hub** (`docker.io`) | Public images, official images | Free public repos; pull rate limits for anonymous and free users; personal and organization access tokens |
| **GitHub Container Registry** (`ghcr.io`) | Projects on GitHub | Free for public images; permissions follow the repository; `GITHUB_TOKEN` in Actions |
| **GitLab Container Registry** | Projects on GitLab | Built into every project; CI job tokens |
| **Amazon ECR**, **Google Artifact Registry**, **Azure Container Registry** | Workloads in that cloud | IAM-based auth, no passwords; lifecycle policies; scanning; fast pulls inside the cloud |
| **Harbor**, **Quay**, **JFrog Artifactory**, **Sonatype Nexus** | Self-hosted, enterprises | Projects, RBAC, replication, scanning, retention, proxy caches |
| **`registry:3`** (CNCF Distribution) | A simple private registry, CI caches, air-gapped sites | The reference implementation; no UI, basic auth only |

Run production images from a registry close to where they run (same cloud and region), for speed and to avoid
depending on a third party's availability during a deploy.

## Logging in

### Tokens, not passwords

Never log in with your account password, and never put it in CI. Every registry has tokens that can be scoped and
revoked:

- **Docker Hub:** a personal access token (Account settings → Personal access tokens) with read-only or read/write
  scope, or an organization access token for automation.
- **GHCR:** in GitHub Actions, the automatic `GITHUB_TOKEN` with `packages: write` (chapter 14). Elsewhere, a personal
  access token (classic) with the `read:packages` or `write:packages` scope.
- **Cloud registries:** short-lived tokens from the cloud's identity system, for example
  `aws ecr get-login-password | docker login --username AWS --password-stdin <account>.dkr.ecr.<region>.amazonaws.com`,
  or better, a credential helper (below).

Always pass secrets on standard input, never as an argument (arguments end up in shell history and `ps` output):

```bash
echo "$GHCR_TOKEN" | docker login ghcr.io -u your-github-user --password-stdin
```

### Where the credentials go

To see what `docker login` does with them, run a registry that requires authentication. `registry:3` supports
`htpasswd` files with bcrypt hashes:

```bash
docker run --rm --entrypoint htpasswd httpd:2-alpine -Bbn alice 'correct-horse' > htpasswd
docker run -d --name regauth -p 127.0.0.1:5002:5000 \
  -v "$PWD/htpasswd:/auth/htpasswd:ro" \
  -e REGISTRY_AUTH=htpasswd -e REGISTRY_AUTH_HTPASSWD_REALM="Snip registry" \
  -e REGISTRY_AUTH_HTPASSWD_PATH=/auth/htpasswd \
  registry:3
```

Without credentials, the registry answers with `401` and a challenge, and a push fails:

```bash
curl -si localhost:5002/v2/ | head -4
docker tag alpine:3 localhost:5002/alpine:3 && docker push localhost:5002/alpine:3
```

```text
HTTP/1.1 401 Unauthorized
Content-Type: application/json
Docker-Distribution-Api-Version: registry/2.0
Www-Authenticate: Basic realm="Snip registry"
push access denied, repository does not exist or may require authorization: authorization failed: no basic auth credentials
```

Log in:

```bash
echo 'correct-horse' | docker login localhost:5002 -u alice --password-stdin
```

```text
WARNING! Your credentials are stored unencrypted in '/home/you/.docker/config.json'.
Configure a credential helper to remove this warning. See
https://docs.docker.com/go/credential-store/

Login Succeeded
```

The warning means what it says. `~/.docker/config.json` now contains:

```json
{ "auths": { "localhost:5002": { "auth": "YWxpY2U6Y29ycmVjdC1ob3JzZQ==" } } }
```

That's `alice:correct-horse` in base64, which is an encoding, not encryption. Anyone who can read the file, or a
backup of your home directory, has your token.

**Credential helpers** fix that. A helper is a small program, `docker-credential-<name>`, that stores credentials in
the operating system's keychain or fetches them from a cloud's identity system:

```json
{
  "credsStore": "osxkeychain",
  "credHelpers": {
    "123456789012.dkr.ecr.eu-west-1.amazonaws.com": "ecr-login",
    "europe-docker.pkg.dev": "gcloud"
  }
}
```

- `credsStore` is the default for all registries: `osxkeychain` on macOS, `wincred` on Windows, `secretservice` or
  `pass` on Linux desktops. Docker Desktop configures `desktop`, which delegates to the right one.
- `credHelpers` picks a helper per registry. `ecr-login` (amazon-ecr-credential-helper) and `gcloud` (part of the
  Google Cloud CLI) produce short-lived tokens from your cloud login, so there's nothing long-lived to store at all.

On servers and CI runners without a keychain, keep tokens short-lived and scoped to read-only where possible,
`docker logout` at the end of a job, and make sure `config.json` isn't readable by others.

```bash
docker logout localhost:5002 && docker rm -f regauth
```

## Running your own registry

`registry:3` is the CNCF Distribution project's registry, the reference implementation of the specification. You've
used it as a throwaway since chapter 14. A persistent one needs storage, authentication and TLS:

```yaml
# compose.yaml for a small private registry
services:
  registry:
    image: registry:3
    restart: unless-stopped
    ports:
      - "127.0.0.1:5000:5000"          # TLS terminates in a reverse proxy in front (chapter 18)
    environment:
      REGISTRY_AUTH: htpasswd
      REGISTRY_AUTH_HTPASSWD_REALM: registry
      REGISTRY_AUTH_HTPASSWD_PATH: /auth/htpasswd
      REGISTRY_STORAGE_DELETE_ENABLED: "true"
    volumes:
      - registry-data:/var/lib/registry
      - ./htpasswd:/auth/htpasswd:ro
volumes:
  registry-data:
```

Every setting in the registry's configuration file (`/etc/distribution/config.yml` in `registry:3`) can be overridden
with a `REGISTRY_…` environment variable, where `_` separates the levels: `REGISTRY_STORAGE_DELETE_ENABLED` is
`storage: delete: enabled:`. Storage can also be S3, Azure Blob Storage or Google Cloud Storage instead of a volume.

Docker only talks plain HTTP to registries on `localhost` (and `127.0.0.0/8`). Anything else needs HTTPS with a valid
certificate, or an `insecure-registries` entry in the daemon configuration. Use HTTPS: a reverse proxy with an
automatic certificate (chapter 18) is the simplest way.

### Deleting images and reclaiming space

Deleting in a registry is two steps. First remove the manifest (deletion must be enabled, which `registry:3`'s default
configuration does). The API deletes **by digest**, and every tag pointing at that digest goes with it:

```bash
D=$(curl -sI -H 'Accept: application/vnd.oci.image.index.v1+json' \
    localhost:5000/v2/snip/manifests/sbom | awk 'tolower($1)=="docker-content-digest:" {print $2}' | tr -d '\r')
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE "localhost:5000/v2/snip/manifests/$D"
```

```text
202
```

The blobs are still on disk, because other manifests may use them. **Garbage collection** removes blobs that no
manifest references any more:

```bash
docker exec reg du -sh /var/lib/registry
docker exec reg registry garbage-collect --dry-run /etc/distribution/config.yml   # look first
docker exec reg registry garbage-collect /etc/distribution/config.yml
docker exec reg du -sh /var/lib/registry
```

```text
203.2M	/var/lib/registry
…
196.1M	/var/lib/registry
```

Only a little was freed here, and that's a trap worth knowing. The deleted tag pointed to an **index**. Deleting it
removed the index, but the per-platform manifests it listed are separate manifests in the repository, now untagged,
and they still reference all the layers. `--delete-untagged` (`-m`) also removes manifests that no tag points to
(directly or through an index), and only then are their layers freed. In a test with two unrelated images, deleting
one and collecting without `-m` left the registry at 134.7 MB; with `-m`, it dropped to 55.3 MB, and the other image
still pulled and ran. Always look at `--dry-run` output first.

Run garbage collection while nothing pushes to the registry (stop it, or put it in read-only mode with
`REGISTRY_STORAGE_MAINTENANCE_READONLY`): a blob uploaded during the collection but not yet referenced by a manifest
could be deleted.

For anything beyond a simple private registry (a web UI, users and projects, retention rules, vulnerability scanning,
replication between sites), use Harbor, which is built on the same Distribution code.

## Deploying by digest

A tag can move; a digest can't. That makes the digest the right thing to deploy:

```bash
docker buildx imagetools inspect localhost:5000/snip:1.7.0 --format '{{json .Manifest}}'
```

```text
{"mediaType":"application/vnd.oci.image.index.v1+json","digest":"sha256:afe1b952357a462af7ee56ad6fe4214e64c26f48958a9cd048e1f60de6816122",…}
```

```yaml
services:
  api:
    image: localhost:5000/snip@sha256:afe1b952357a462af7ee56ad6fe4214e64c26f48958a9cd048e1f60de6816122
```

With a digest:

- **Every server runs the same bytes,** even if someone pushes a different image under `1.7.0` in the meantime.
- **Rollbacks are exact:** the previous deployment's digest is the previous image, not "whatever that tag points to
  now".
- **`docker compose pull` and restarts are predictable:** nothing changes unless the digest in the file changes.

The common compromise in files people edit is both: `image: snip:1.7.0@sha256:afe1…`. The tag is ignored for pulling
and kept for humans.

After a pull, the local image records where it came from:

```bash
docker image inspect localhost:5000/snip@sha256:afe1b952357a… --format '{{json .RepoDigests}}'
```

```text
["localhost:5000/snip@sha256:afe1b952357a462af7ee56ad6fe4214e64c26f48958a9cd048e1f60de6816122"]
```

### Promoting without rebuilding

"Build once, promote the same artifact" (chapter 14) means a release is a new *tag* on an existing image, not a new
build. `docker buildx imagetools create` writes a new tag (or copies to another repository or registry) on the
registry side, without pulling the image to your machine, and keeps all platforms and attestations:

```bash
docker buildx imagetools create -t localhost:5000/snip:prod localhost:5000/snip@sha256:afe1b952357a…
curl -sI -H 'Accept: application/vnd.oci.image.index.v1+json' localhost:5000/v2/snip/manifests/prod \
  | grep -i docker-content-digest
```

```text
Docker-Content-Digest: sha256:afe1b952357a462af7ee56ad6fe4214e64c26f48958a9cd048e1f60de6816122
```

Same digest: the `prod` tag is literally the tested image. The same command copies between registries
(`-t ghcr.io/you/snip:1.7.0 localhost:5000/snip@sha256:…`). Standalone tools such as `crane`, `regctl` and `skopeo`
do the same and more (copying whole repositories, listing, deleting) without a Docker daemon.

## Retention and cleanup

Registries grow forever unless you prune them: every CI run pushes a `sha-…` tag, and each tag keeps layers alive.
Every hosted registry has some form of retention policy:

- **Cloud registries:** lifecycle policies, for example "keep the last 30 images tagged `sha-*`, delete untagged images
  after 7 days" (ECR lifecycle policies, Artifact Registry cleanup policies, ACR retention).
- **GHCR:** package versions can be deleted in the UI, through the API, or from a scheduled workflow
  (`actions/delete-package-versions`).
- **Harbor:** tag retention rules per project.
- **`registry:3`:** delete manifests through the API with a script, then garbage-collect.

A safe policy keeps every release tag (`1.7.0`), everything currently deployed (by digest), and the recent history of
`main`, and expires the rest. Never delete what a running system might need to pull again after a restart or a
rollback.

## Docker Hub pull limits and mirrors

Docker Hub limits pulls per 6 hours: **100** for anonymous users (per IPv4 address or IPv6 /64), **200** for
authenticated free users, and more for paid plans. A "pull" is a manifest request; layers don't count. Shared CI
runners and office NAT gateways hit the anonymous limit fast:

```text
toomanyrequests: You have reached your unauthenticated pull rate limit.
```

Defenses, in order of effort:

1. **Log in** in CI (`docker/login-action` with a read-only Docker Hub token): the limit becomes per account, and higher.
2. **Use a mirror for Docker Hub.** The daemon setting `registry-mirrors` makes Docker try a mirror first for images
   from Docker Hub:

   ```json
   { "registry-mirrors": ["https://mirror.gcr.io"] }
   ```

   `mirror.gcr.io` is Google's public cache of popular Docker Hub images. This tutorial's examples were tested on a
   machine configured exactly like that. Mirrors only apply to `docker.io` images, not to `ghcr.io` or others.
3. **Run a pull-through cache.** `registry:3` can act as a caching proxy for Docker Hub
   (`REGISTRY_PROXY_REMOTEURL=https://registry-1.docker.io`, with Hub credentials for higher limits), and Harbor,
   Artifactory and the cloud registries offer the same. Point `registry-mirrors` at it.
4. **Copy what you depend on** into your own registry (`imagetools create` or `crane copy`) and build `FROM` there.
   Your builds then never depend on Docker Hub's availability.

## Moving images without a registry

Sometimes there's no registry between two machines: an air-gapped network, a customer's server, a quick test.
`docker save` writes images to a tar archive, and `docker load` reads it back:

```bash
docker save snip:1.7 | gzip > snip-1.7.tar.gz       # 55 MB here
scp snip-1.7.tar.gz server:
ssh server 'docker load -i snip-1.7.tar.gz'
```

```text
Loaded image: snip:1.7
```

`docker save --platform linux/arm64` picks one platform of a multi-platform image. A build can also produce an OCI
archive directly, without going through the image store: `docker buildx build --output type=oci,dest=snip.tar .`.
Tools like `skopeo copy oci-archive:snip.tar docker://registry.internal/snip:1.7` then push it into the registry on
the other side. For more than a handful of images, a registry on the inside, filled by copying, is less error-prone.

## Signing images

A digest proves *integrity*: these are exactly the bytes that were pushed. It says nothing about *who* pushed them. If
an attacker can push to your repository (a leaked token, a compromised CI job), the digest of their image is just as
valid. **Signatures** let a deployment check that an image was produced by your pipeline.

The common tool today is **cosign** from the Sigstore project. A signature is stored in the registry next to the image
(as an extra manifest that refers to the image's digest), so no extra infrastructure is needed. Docker's older
mechanism, Docker Content Trust (Notary v1), is being retired: Docker's documentation announces that the
`notary.docker.io` service shuts down on December 8, 2026. Don't start new work with it.

### Keyless signing in CI

The recommended setup has no long-lived keys at all. In GitHub Actions, cosign uses the workflow's OIDC identity to
get a short-lived certificate from Sigstore's certificate authority (Fulcio), signs, and records the signature in a
public transparency log (Rekor). The certificate says, verifiably, "this was signed by workflow `ci.yml` in repository
`you/snip` at ref `refs/tags/v1.7.0`". Added to chapter 14's `publish` job:

> **Just a taste — you don't need to learn or fully understand this now.** These workflow steps need a real GitHub
> repository and Sigstore's public services; the capstone's workflow (chapter 22) includes them.

```yaml
    permissions:
      contents: read
      packages: write
      id-token: write                      # lets the job request an OIDC token for signing
    steps:
      # … checkout, setup-buildx, login, metadata as before …
      - name: Build and push
        id: build
        uses: docker/build-push-action@v7
        with:
          # … as before …

      - uses: sigstore/cosign-installer@v4

      - name: Sign the image
        env:
          DIGEST: ${{ steps.build.outputs.digest }}
        run: cosign sign --yes "ghcr.io/${{ github.repository_owner }}/snip@${DIGEST}"
```

Sign the **digest**, never a tag: signing a tag signs whatever it points to at that moment.

Verifying names the identity you trust, not a key:

```bash
cosign verify ghcr.io/you/snip@sha256:afe1… \
  --certificate-identity-regexp '^https://github\.com/you/snip/\.github/workflows/ci\.yml@refs/tags/v' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

This succeeds only for images signed by that workflow, in that repository, for a version tag. An image pushed with a
stolen token has no such signature.

### Signing with a key pair

Without access to the public Sigstore services (a private registry, an isolated network), cosign also works with a
classic key pair. This is what that looks like against the local registry:

```bash
cosign generate-key-pair                     # writes cosign.key (encrypted with a password) and cosign.pub
cosign sign --yes --key cosign.key --use-signing-config=false --tlog-upload=false \
  localhost:5000/snip@sha256:afe1b952357a…
cosign verify --key cosign.pub --insecure-ignore-tlog=true localhost:5000/snip@sha256:afe1b952357a…
```

```text
Verification for localhost:5000/snip@sha256:afe1b952357a462af7ee56ad6fe4214e64c26f48958a9cd048e1f60de6816122 --
The following checks were performed on each of these signatures:
  - The cosign claims were validated
  - Existence of the claims in the transparency log was verified offline
  - The signatures were verified against the specified public key
```

and for an image that nobody signed:

```text
error during command execution: no signatures found
```

The extra flags turn off the public transparency log, and cosign warns that this is less auditable. (Cosign 3 also
prints that `--tlog-upload` is deprecated in favor of a "signing config" file that lists the services to use; the
effect is the same.) The signature shows up in the registry as an extra tag named after the digest
(`sha256-afe1b952357a…` in the tag list at the start of this chapter).

### Enforcing signatures

A signature nobody checks protects nothing. Verification belongs in the deployment path:

- In a deploy script, run `cosign verify` and deploy only the verified digest.
- In Kubernetes, admission controllers (Sigstore's policy-controller, Kyverno, Connaisseur) reject unsigned images.
- GitHub also offers its own artifact attestations (`actions/attest@v4`, verified with `gh attestation verify`),
  built on the same Sigstore technology.

Chapter 16 puts signatures, SBOMs and scanning together into a supply-chain view.

## Key takeaways

- Registries speak the OCI Distribution API: blobs and manifests by digest, tags as movable names. `curl` and
  `docker buildx imagetools` let you look inside.
- Pick a registry close to where images run; use cloud IAM or tokens, never passwords, and pass them on stdin.
- `docker login` stores credentials base64-encoded in `~/.docker/config.json`; use credential helpers.
- `registry:3` is a fine private registry: add authentication, TLS in front, and garbage collection after deletions.
- Deploy by digest. Promote by adding tags to the tested image with `imagetools create`, never by rebuilding.
- Set retention policies before the registry fills up, and never delete what a rollback might need.
- Log in to Docker Hub in CI, and use a mirror or pull-through cache for the images you depend on.
- `docker save` / `docker load` and OCI archives move images where no registry reaches.
- Sign digests in CI (keyless with cosign), verify identity at deploy time; Docker Content Trust is being retired.

## Exercises

1. **Read a manifest by hand.** Using only `curl` against a local registry, find the digest of the `linux/arm64`
   image inside a multi-platform tag, then download that image's config blob and print its `Cmd` and `User`.

<details>
<summary>Solution</summary>

```bash
R=localhost:5000/v2/snip
arm=$(curl -s -H 'Accept: application/vnd.oci.image.index.v1+json' $R/manifests/1.7.0 \
  | node -pe 'JSON.parse(require("fs").readFileSync(0)).manifests
      .find(m => m.platform?.architecture === "arm64").digest')
config=$(curl -s -H 'Accept: application/vnd.oci.image.manifest.v1+json' $R/manifests/$arm \
  | node -pe 'JSON.parse(require("fs").readFileSync(0)).config.digest')
curl -s $R/blobs/$config | node -pe 'const c = JSON.parse(require("fs").readFileSync(0)).config; [c.Cmd, c.User]'
```

```text
[ [ 'src/server.js' ], '65532' ]
```

The index lists one manifest per platform (plus attestation manifests with platform `unknown/unknown`). The
manifest's `config` is a blob like any layer, fetched from `/blobs/`. This is all `docker pull` does, plus
downloading and unpacking the layers.

</details>

2. **Promote a release.** Your CI pushed `localhost:5000/snip:sha-3f2a1c9` and it passed staging. Tag it as `1.8.0` and
   `prod` without rebuilding or pulling, and prove that all three tags point to the same digest.

<details>
<summary>Solution</summary>

```bash
docker buildx imagetools create \
  -t localhost:5000/snip:1.8.0 -t localhost:5000/snip:prod \
  localhost:5000/snip:sha-3f2a1c9
for t in sha-3f2a1c9 1.8.0 prod; do
  curl -sI -H 'Accept: application/vnd.oci.image.index.v1+json' \
    localhost:5000/v2/snip/manifests/$t | grep -i docker-content-digest
done
```

All three lines show the same digest. The registry stored no new layers and no new manifest, only two new tags.
Better still, pass the source by digest (`localhost:5000/snip@sha256:…`) instead of by tag, so that the promotion
can't pick up something pushed to `sha-3f2a1c9` in the meantime.

</details>

3. **A credential helper for a script.** A deploy script on a server must pull from a private registry. List three ways
   to give it credentials, from worst to best, and say what's wrong with each of the worse ones.

<details>
<summary>Solution</summary>

1. **`docker login -u deploy -p <password>` in the script**: the password is in the script, in the shell history and
   visible in `ps` while it runs; it's then stored base64-encoded in `config.json`. Worst.
2. **A read-only token passed with `--password-stdin` from a secret file** readable only by the deploy user, and
   `docker logout` afterwards: no secret in the script or process list, minimal scope, revocable. Still a long-lived
   token on the server.
3. **A credential helper backed by the platform's identity** (`ecr-login` with an EC2 instance role, `gcloud` with a
   service account, a cloud's managed identity): no stored secret at all, short-lived tokens, permissions managed in
   IAM. Best, where available.

</details>

4. **Garbage collection.** Push two versions of an image to a local `registry:3` with a named volume, delete the older
   one through the API, and measure the volume's size before deleting, after deleting, and after garbage collection.
   Explain the three numbers.

<details>
<summary>Solution</summary>

```bash
docker run -d --name gcreg -p 127.0.0.1:5003:5000 -v gcreg:/var/lib/registry registry:3
docker tag snip:1.0 localhost:5003/snip:old && docker push localhost:5003/snip:old
docker tag snip:1.7 localhost:5003/snip:new && docker push localhost:5003/snip:new
size() { docker exec gcreg du -sh /var/lib/registry; }
size                                                   # both versions
D=$(curl -sI -H 'Accept: application/vnd.oci.image.index.v1+json' localhost:5003/v2/snip/manifests/old \
  | awk 'tolower($1)=="docker-content-digest:" {print $2}' | tr -d '\r')
curl -X DELETE localhost:5003/v2/snip/manifests/$D
size                                                   # unchanged
docker exec gcreg registry garbage-collect --delete-untagged /etc/distribution/config.yml >/dev/null
size                                                   # smaller
docker rm -f gcreg && docker volume rm gcreg
```

Measured with `snip:1.0` (chapter 4's single-stage image) as `old` and `snip:1.7` as `new`: 134.8 MB, then still
134.8 MB, then 55.3 MB. Deleting the manifest only removes the reference (and the `old` tag); the blobs stay, so the
size doesn't change. Garbage collection with `--delete-untagged` removes the platform manifests that belonged to the
deleted index and then every blob nothing references any more. Layers shared between the two images would have
survived; here the images share nothing, so `old` disappears completely and what remains is `new`.

</details>

5. **Verify before deploying.** Write a small shell function `deploy_verified IMAGE_BY_DIGEST` that refuses to run
   `docker compose up -d` unless `cosign verify` with your public key succeeds, and that refuses tags (it must be given
   `name@sha256:…`).

<details>
<summary>Solution</summary>

```bash
deploy_verified() {
  image="$1"
  case "$image" in
    *@sha256:*) ;;
    *) echo "refusing: '$image' is not pinned by digest" >&2; return 1 ;;
  esac
  if ! cosign verify --key cosign.pub "$image" >/dev/null 2>&1; then
    echo "refusing: no valid signature for $image" >&2
    return 1
  fi
  SNIP_IMAGE="$image" docker compose up -d --wait
}
```

with `image: ${SNIP_IMAGE:?}` in the Compose file. Checking for a digest first matters: verifying a tag and then
deploying the same tag leaves a window in which the tag can move. (For a local registry without the public
transparency log, add the `--insecure-ignore-tlog=true` flag shown in this chapter; with keyless signing, use the
`--certificate-identity…` options instead of `--key`.)

</details>

---
← Previous: [CI pipelines and multi-platform builds](14-ci-pipelines-and-multi-platform-builds.md) | Next: [Container security](16-container-security.md) →
