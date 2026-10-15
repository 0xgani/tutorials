import { createClient } from 'redis';

// Connects to Valkey (or Redis: same protocol). VALKEY_URL looks like redis://valkey:6379.
// The client reconnects on its own if the connection drops later.
export async function connectValkey(url) {
  const client = createClient({ url });
  client.on('error', (err) => console.error(`valkey: ${err.message}`));
  await client.connect();
  return client;
}
