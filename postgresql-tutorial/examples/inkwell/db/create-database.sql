-- Creates the Inkwell database, owned by inkwell_owner. Run it as a superuser after db/roles.sql, with the
-- database name in the psql variable dbname:
--   psql -X -v ON_ERROR_STOP=1 -d postgres -v dbname=inkwell_dev -f db/create-database.sql
-- (The API's scripts/reset-db.js does the same, plus the migrations and the sample data.)

-- template0 is required when the locale provider differs from the cluster default (chapter 11).
CREATE DATABASE :"dbname"
    OWNER inkwell_owner
    TEMPLATE template0
    ENCODING 'UTF8'
    LOCALE_PROVIDER icu
    ICU_LOCALE 'en';

-- Every session stores and prints timestamps in UTC; the application formats them for people.
ALTER DATABASE :"dbname" SET timezone TO 'UTC';
-- Nobody may connect or create temporary tables unless granted (migration 004 grants it to Inkwell's roles).
REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"dbname" TO inkwell_migrate;
