-- Rebuild the Inkwell database from scratch: drop it, create it, load the schema and the sample data.
-- Run it while connected to ANOTHER database (you can't drop the one you are connected to):
--   psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql

DROP DATABASE IF EXISTS inkwell WITH (FORCE);

-- template0 is required when choosing a locale provider that differs from the cluster default.
-- ICU's 'en' collation sorts text the same way on every operating system.
CREATE DATABASE inkwell
    TEMPLATE template0
    ENCODING 'UTF8'
    LOCALE_PROVIDER icu
    ICU_LOCALE 'en';

-- Every session in this database uses UTC, so timestamps print the same for every reader.
ALTER DATABASE inkwell SET timezone TO 'UTC';

\connect inkwell
\ir schema.sql
\ir seed.sql
ANALYZE;
