# Snip, chapter 4: a first, single-stage image.
FROM node:24-slim

WORKDIR /app

# Dependencies first: this layer is rebuilt only when the manifests change.
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# Then the application code, which changes on almost every build.
COPY src ./src

ENV NODE_ENV=production
EXPOSE 3000
CMD ["node", "src/server.js"]
