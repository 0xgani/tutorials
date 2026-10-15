import express from 'express';
import { randomBytes, randomUUID } from 'node:crypto';
import { observeRequest, renderMetrics } from './metrics.js';

const quiet = { info() {}, warn() {}, error() {} };

// Builds the Express app. The store is passed in so that later chapters can
// swap the in-memory map for PostgreSQL without touching the routes.
export function createApp({ store, baseUrl, logger = quiet }) {
  const app = express();

  // One log line and one metrics observation per request, written when the response is done.
  app.use((req, res, next) => {
    const start = process.hrtime.bigint();
    req.id = req.get('x-request-id')?.slice(0, 64) ?? randomUUID();   // a proxy may already set one
    res.set('x-request-id', req.id);
    res.on('finish', () => {
      const seconds = Number(process.hrtime.bigint() - start) / 1e9;
      const route = req.route?.path ?? 'unmatched';
      observeRequest({ method: req.method, route, status: res.statusCode, seconds });
      if (route === '/healthz' || route === '/metrics') return;   // probes would drown everything else
      logger.info({
        reqId: req.id, method: req.method, url: req.originalUrl, route,
        status: res.statusCode, ms: Math.round(seconds * 1e4) / 10,
      }, 'request');
    });
    next();
  });

  app.use(express.json({ limit: '10kb' }));

  // Prometheus scrapes this. Keep it off the public internet (chapter 18).
  app.get('/metrics', (req, res) => {
    res.type('text/plain; version=0.0.4').send(renderMetrics());
  });

  app.get('/healthz', (req, res) => {
    res.json({ status: 'ok' });
  });

  app.post('/api/links', async (req, res) => {
    const url = parseUrl(req.body?.url);
    if (!url) {
      return res.status(400).json({ error: 'body must be {"url": "http(s)://..."}' });
    }
    const code = randomBytes(5).toString('base64url').slice(0, 7);
    const link = await store.create({ code, url });
    res.status(201).json({ ...link, shortUrl: `${baseUrl}/${code}` });
  });

  app.get('/api/links/:code', async (req, res) => {
    const link = await store.get(req.params.code);
    if (!link) return res.status(404).json({ error: 'not found' });
    res.json(link);
  });

  app.get('/:code', async (req, res) => {
    const url = await store.resolve(req.params.code);
    if (!url) return res.status(404).type('text').send('not found\n');
    await store.recordClick(req.params.code);
    res.redirect(302, url);
  });

  // Errors thrown by routes (a database that's down, for example): log them with the
  // request ID, and answer without leaking details to the client.
  app.use((err, req, res, next) => {
    if (res.headersSent) return next(err);
    const status = err.status ?? err.statusCode ?? 500;
    if (status >= 500) logger.error({ err, reqId: req.id }, 'request failed');
    res.status(status).json({ error: status >= 500 ? 'internal error' : err.message });
  });

  return app;
}

function parseUrl(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
  } catch {
    return null;
  }
}
