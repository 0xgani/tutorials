# Snip's builds as code. Run from this directory:
#   docker buildx bake                 test, then build snip:dev-local for this machine
#   docker buildx bake --print         show the resolved configuration as JSON
#   TAG=1.7.0 REGISTRY=ghcr.io/you docker buildx bake release   multi-platform, attested, pushed

variable "REGISTRY" {
  default = "localhost:5000"
}

variable "TAG" {
  default = "dev-local"
}

variable "GIT_SHA" {
  default = "unknown"
}

group "default" {
  targets = ["test", "image"]
}

# Settings every target shares.
target "_common" {
  context    = "."
  dockerfile = "Dockerfile"
  args = {
    NODE_VERSION = "24"
  }
}

# The unit tests: builds the "test" stage, exports nothing.
target "test" {
  inherits = ["_common"]
  target   = "test"
  output   = ["type=cacheonly"]
}

# The production image for this machine, loaded into the local image store.
target "image" {
  inherits = ["_common"]
  target   = "runtime"
  tags     = ["snip:${TAG}"]
  args = {
    APP_VERSION = TAG
    GIT_SHA     = GIT_SHA
  }
}

# The release: both platforms, SBOM and full provenance, pushed to the registry.
target "release" {
  inherits   = ["image"]
  platforms  = ["linux/amd64", "linux/arm64"]
  tags       = ["${REGISTRY}/snip:${TAG}"]
  attest     = ["type=sbom", "type=provenance,mode=max"]
  output     = ["type=registry"]
  cache-from = ["type=registry,ref=${REGISTRY}/snip:buildcache"]
  cache-to   = ["type=registry,ref=${REGISTRY}/snip:buildcache,mode=max"]
}
