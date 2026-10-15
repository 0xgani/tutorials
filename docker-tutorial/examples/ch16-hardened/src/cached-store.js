// Wraps a store with Valkey: redirects are answered from a cache, and clicks are
// queued on a list for the worker instead of updating PostgreSQL on every request.
export function withValkey(store, valkey, { ttlSeconds = 3600 } = {}) {
  return {
    create: (link) => store.create(link),
    get: (code) => store.get(code),

    async resolve(code) {
      const key = `url:${code}`;
      const cached = await valkey.get(key);
      if (cached) return cached;
      const url = await store.resolve(code);
      if (url) await valkey.set(key, url, { EX: ttlSeconds });
      return url;
    },

    async recordClick(code) {
      await valkey.lPush('clicks', code);
    },

    async close() {
      await valkey.close();
      await store.close?.();
    },
  };
}
