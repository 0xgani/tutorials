# syntax=docker/dockerfile:1
# check=error=true

# Snip, chapter 14: ready for CI and multi-platform builds.
#   docker buildx bake                       test, then build the image (see docker-bake.hcl)
#   docker build --target test .             run the unit tests
#   docker build -t snip:1.7 .               final image (distroless), for this machine's platform
#   docker build --platform linux/amd64,linux/arm64 -t registry.example.com/snip:1.7 --push .
#   docker build --target dev -t snip:dev .  development image (chapter 12)

ARG NODE_VERSION=24

# deps: production dependencies. It runs on the build machine's own platform ($BUILDPLATFORM),
# even when the image is for another platform: Snip's dependencies are pure JavaScript, so the
# installed node_modules work everywhere, and no emulation is needed.
FROM --platform=$BUILDPLATFORM node:${NODE_VERSION}-slim AS deps
WORKDIR /app
ENV NODE_ENV=production
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev

# test: the unit tests, also on the build platform. The build fails if a test fails.
FROM deps AS test
COPY src ./src
COPY test ./test
RUN node --test

# base: the target platform's Node.js image, for the stages that end up in an image
FROM node:${NODE_VERSION}-slim AS base
WORKDIR /app
ENV NODE_ENV=production

# dev: local development (chapter 12)
FROM base AS dev
ENV NODE_ENV=development
RUN chown node:node /app
USER node
COPY --chown=node:node package.json package-lock.json ./
RUN --mount=type=cache,target=/home/node/.npm,uid=1000,gid=1000 \
    npm ci
COPY --chown=node:node . .
EXPOSE 3000 9229
CMD ["node", "--watch", "src/server.js"]

# runtime-slim: a Debian slim runtime, for when you need a shell in production
FROM base AS runtime-slim
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY src ./src
COPY migrations ./migrations
USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --start-interval=1s --retries=3 \
  CMD ["node", "src/healthcheck.js"]
CMD ["node", "src/server.js"]

# runtime: the default target. Distroless, non-root (UID 65532). Only COPY instructions,
# so building it for another platform needs no emulation.
FROM gcr.io/distroless/nodejs${NODE_VERSION}-debian13:nonroot AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY src ./src
COPY migrations ./migrations

ARG APP_VERSION=dev
ARG GIT_SHA=unknown
LABEL org.opencontainers.image.title="snip" \
      org.opencontainers.image.description="A tiny URL shortener" \
      org.opencontainers.image.source="https://github.com/example/snip" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${GIT_SHA}"
ENV APP_VERSION=${APP_VERSION}

EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --start-interval=1s --retries=3 \
  CMD ["/nodejs/bin/node", "src/healthcheck.js"]
# The distroless image's entrypoint is /nodejs/bin/node, so CMD holds only its arguments.
CMD ["src/server.js"]
