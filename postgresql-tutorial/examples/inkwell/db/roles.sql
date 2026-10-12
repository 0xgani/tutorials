-- Inkwell's roles (chapter 21). Roles belong to the whole cluster, not to one database, so this script runs
-- once per cluster, as a superuser, before any Inkwell database is created. It is safe to run again: it creates
-- the missing roles and brings the existing ones to the state described here.
--
--   inkwell_owner      owns every object; nobody logs in as it
--   inkwell_migrate    runs migrations: logs in, then switches to inkwell_owner
--   inkwell_readonly   group: read every table
--   inkwell_readwrite  group: readonly + insert, update, delete
--   inkwell_portal     group: what a signed-in customer may do (row-level security limits it to their rows)
--   inkwell_app        the API: readwrite, and may switch to inkwell_portal for customer requests
--   inkwell_report     the reporting script: readonly + the reporting schema
--
-- The passwords are for development only. In production, set real ones with \password and keep them out of files.

SET client_min_messages = warning;

DO $$
DECLARE
    r record;
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('inkwell_owner',     'NOLOGIN', NULL),
            ('inkwell_readonly',  'NOLOGIN', NULL),
            ('inkwell_readwrite', 'NOLOGIN', NULL),
            ('inkwell_portal',    'NOLOGIN', NULL),
            ('inkwell_migrate',   'LOGIN',   'migrate-dev-password'),
            ('inkwell_app',       'LOGIN',   'app-dev-password'),
            ('inkwell_report',    'LOGIN',   'report-dev-password')
        ) AS t(name, login, password)
    LOOP
        IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = r.name) THEN
            EXECUTE format('CREATE ROLE %I', r.name);
        END IF;
        EXECUTE format('ALTER ROLE %I %s NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD %L',
                       r.name, r.login, r.password);
    END LOOP;
END;
$$;

ALTER ROLE inkwell_app CONNECTION LIMIT 30;

GRANT inkwell_readonly TO inkwell_readwrite;
GRANT inkwell_readwrite TO inkwell_app;
GRANT inkwell_readonly TO inkwell_report;

-- SET TRUE, INHERIT FALSE (16+): the role may switch with SET ROLE, but doesn't use the privileges otherwise.
GRANT inkwell_portal TO inkwell_app WITH INHERIT FALSE, SET TRUE;
GRANT inkwell_owner TO inkwell_migrate WITH INHERIT FALSE, SET TRUE;

-- Every migration session starts as the owner, so new objects get the right owner without SET ROLE in each file.
ALTER ROLE inkwell_migrate SET role = 'inkwell_owner';
-- Safety nets (chapter 21). The report script is read-only unless it explicitly starts a READ WRITE transaction.
ALTER ROLE inkwell_app SET statement_timeout = '5s';
ALTER ROLE inkwell_app SET idle_in_transaction_session_timeout = '30s';
ALTER ROLE inkwell_report SET default_transaction_read_only = on;
ALTER ROLE inkwell_report SET statement_timeout = '2min';
