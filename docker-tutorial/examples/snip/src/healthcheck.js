// Used by the image's HEALTHCHECK: exits 0 if the server answers /healthz, 1 otherwise.
// Written in Node so the image doesn't need curl or wget.
const port = process.env.PORT ?? 3000;

try {
  const res = await fetch(`http://127.0.0.1:${port}/healthz`, { signal: AbortSignal.timeout(2000) });
  process.exit(res.ok ? 0 : 1);
} catch {
  process.exit(1);
}
