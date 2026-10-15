import express from 'express';
import { randomBytes } from 'node:crypto';

// Builds the Express app. The store is passed in so that later chapters can
// swap the in-memory map for PostgreSQL without touching the routes.
export function createApp({ store, baseUrl }) {
  const app = express();
  app.use(express.json({ limit: '10kb' }));

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
    const link = await store.get(req.params.code);
    if (!link) return res.status(404).type('text').send('not found\n');
    await store.recordClick(link.code);
    res.redirect(302, link.url);
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
