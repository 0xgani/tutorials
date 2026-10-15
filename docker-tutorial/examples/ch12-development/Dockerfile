# syntax=docker/dockerfile:1
# check=error=true

# Snip, chapter 12: chapter 11's build plus a "dev" stage for local development.
# One image runs the API, the worker (src/worker.js) and the migration job (src/migrate.js).
# Usually built by Compose (docker compose build); by hand:
#   docker build -t snip:1.5 .                   final image (distroless)
#   docker build --target dev -t snip:dev .      development image (all dependencies, node --watch)
#   docker build --target test .                 run the tests
#   docker build --target runtime-slim -t snip:1.5-slim .   Debian slim runtime with a shell

ARG NODE_VERSION=24

# base: settings shared by the Node-based stages
FROM node:${NODE_VERSION}-slim AS base
WORKDIR /app
ENV NODE_ENV=production

# deps: production dependencies only, installed once and copied into the runtime stages
FROM base AS deps
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev

# test: production dependencies plus the test files; the build fails if a test fails
FROM deps AS test
COPY src ./src
COPY test ./test
RUN node --test

# dev: local development (chapter 12). All dependencies, including devDependencies; Compose Watch
# syncs source changes into /app and node --watch restarts the process.
FROM base AS dev
ENV NODE_ENV=development
RUN chown node:node /app
USER node
COPY --chown=node:node package.json package-lock.json ./
RUN --mount=type=cache,target=/home/node/.npm,uid=1000,gid=1000 \
    npm ci
COPY --chown=node:node . .
EXPOSE 3000 9229
CMD ["node", "--watch-path=src", "src/server.js"]

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

# runtime: the default target. Distroless: Node.js and its runtime libraries, no shell,
# no package manager, running as the unprivileged "nonroot" user (UID 65532).
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
