#!/bin/bash
# Runs once, when the Postgres volume is first created. Same roles as
# docker/postgres/init.sql, but with the passwords from deploy/.env.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" \
    -v app_password="$DB_PASSWORD" \
    -v migrator_password="$DB_MIGRATOR_PASSWORD" <<'SQL'
CREATE ROLE wabot_migrator LOGIN PASSWORD :'migrator_password' NOBYPASSRLS;
CREATE ROLE wabot_app LOGIN PASSWORD :'app_password' NOBYPASSRLS NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE wabot OWNER wabot_migrator;
\c wabot
CREATE EXTENSION IF NOT EXISTS vector;
GRANT CONNECT ON DATABASE wabot TO wabot_app;
GRANT USAGE ON SCHEMA public TO wabot_app;
ALTER SCHEMA public OWNER TO wabot_migrator;
SQL
