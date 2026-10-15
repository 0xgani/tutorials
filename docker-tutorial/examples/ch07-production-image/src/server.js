import { createApp } from './app.js';
import { createMemoryStore } from './memory-store.js';

const port = Number(process.env.PORT ?? 3000);
const baseUrl = process.env.BASE_URL ?? `http://localhost:${port}`;

const app = createApp({ store: createMemoryStore(), baseUrl });
const server = app.listen(port, () => {
  console.log(`snip listening on port ${port} (node ${process.version}, pid ${process.pid})`);
});

// Docker stops a container by sending SIGTERM to its main process, then SIGKILL
// after a grace period (10 s by default). Shut down cleanly before that happens.
function shutdown(signal) {
  console.log(`${signal} received, closing the server`);
  server.close(() => {
    console.log('all connections closed, exiting');
    process.exit(0);
  });
  server.closeIdleConnections();
  // Don't hang forever on a stuck keep-alive connection.
  setTimeout(() => {
    console.error('could not close connections in time, forcing exit');
    process.exit(1);
  }, 8000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
