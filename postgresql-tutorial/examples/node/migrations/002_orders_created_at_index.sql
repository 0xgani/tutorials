-- Supports the "newest orders first" pages (keyset pagination, chapter 22).
-- On a big, busy table, build it with CREATE INDEX CONCURRENTLY instead, in a migration
-- of its own that runs outside a transaction (chapter 15).
CREATE INDEX orders_created_at_id_idx ON orders (created_at DESC, id DESC);
