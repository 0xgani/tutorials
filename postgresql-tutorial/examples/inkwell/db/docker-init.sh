#!/bin/sh
# Used by compose.yaml: the postgres image runs this once, when it initializes an empty data directory.
# It creates Inkwell's roles and the inkwell_dev database; the API container then runs the migrations.
set -e
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -f /inkwell/db/roles.sql
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -v dbname=inkwell_dev -f /inkwell/db/create-database.sql
