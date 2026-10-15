import os from 'node:os';
import v8 from 'node:v8';
import { createApp } from './app.js';
import { createMemoryStore } from './memory-store.js';
import { createPgStore } from './pg-store.js';
import { withValkey } from './cached-store.js';
import { connectValkey } from './valkey.js';
import { loadConfigOrExit, describeConfig } from './config.js';
import { logger } from './logger.js';

const config = loadConfigOrExit();
const mb = (bytes) => Math.round(bytes / 1048576);
// What this process can use: the container's memory limit and the heap size V8 chose.
// A crash later is much easier to explain with these in the log. Without a limit,
// constrainedMemory() returns 0 or a huge number, depending on the kernel's cgroup version.
const limit = process.constrainedMemory();
logger.info({
  config: describeConfig(config),
  node: process.version,
  memoryLimitMB: limit > 0 && limit < os.totalmem() ? mb(limit) : null,
  heapLimitMB: mb(v8.getHeapStatistics().heap_size_limit),
}, 'starting');

// With a database URL, links live in PostgreSQL; without it, in memory.
// With a Valkey URL too, redirects are cached and clicks go to the worker.
let store = config.databaseUrl ? await createPgStore(config.databaseUrl) : createMemoryStore();
if (config.valkeyUrl) {
  store = withValkey(store, await connectValkey(config.valkeyUrl));
}

const { port } = config;
const app = createApp({ store, baseUrl: config.baseUrl, logger });
const server = app.listen(port, () => {
  logger.info({ port }, 'listening');
});

// Docker stops a container by sending SIGTERM to its main process, then SIGKILL
// after a grace period (10 s by default). Shut down cleanly before that happens.
function shutdown(signal) {
  logger.info({ signal }, 'closing the server');
  server.close(async () => {
    await store.close?.();
    logger.info('all connections closed, exiting');
    process.exit(0);
  });
  server.closeIdleConnections();
  // Don't hang forever on a stuck keep-alive connection.
  setTimeout(() => {
    logger.error('could not close connections in time, forcing exit');
    process.exit(1);
  }, 8000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
