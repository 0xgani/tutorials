// Starts the API and shuts it down cleanly: on SIGTERM (from Docker, systemd, Kubernetes) or Ctrl+C, stop
// accepting connections, let running requests finish, then close the database pool.
import { createServer } from 'node:http';
import { config } from './config.js';
import { pool } from './db.js';
import { handleRequest } from './app.js';

const server = createServer(handleRequest);

server.listen(config.port, () => {
  console.log(`inkwell-api listening on http://localhost:${config.port}`);
});

async function shutdown(signal) {
  console.log(`${signal} received, shutting down`);
  server.close(async () => {
    await pool.end();
    console.log('bye');
  });
  server.closeIdleConnections(); // keep-alive connections would otherwise keep the server open
  setTimeout(() => process.exit(1), 10_000).unref(); // last resort if a request hangs
}

process.once('SIGTERM', shutdown);
process.once('SIGINT', shutdown);
