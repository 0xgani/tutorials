// Keeps links in a Map. Everything is lost when the process exits,
// which is exactly the problem chapter 10 solves with PostgreSQL.
export function createMemoryStore() {
  const links = new Map();
  return {
    async create({ code, url }) {
      const link = { code, url, clicks: 0, createdAt: new Date().toISOString() };
      links.set(code, link);
      return link;
    },
    async get(code) {
      return links.get(code) ?? null;
    },
    async resolve(code) {
      return links.get(code)?.url ?? null;
    },
    async recordClick(code) {
      const link = links.get(code);
      if (link) link.clicks += 1;
    },
  };
}
