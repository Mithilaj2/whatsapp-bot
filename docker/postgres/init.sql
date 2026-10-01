-- Runs once when the Postgres container is first created.
-- Two roles, per the plan's "separate roles for app and migrations":
--   wabot_migrator owns the schema and runs migrations.
--   wabot_app is what the web app and workers connect as. It owns nothing and
--   cannot bypass row-level security.
CREATE ROLE wabot_migrator LOGIN PASSWORD 'migrator' NOBYPASSRLS;
CREATE ROLE wabot_app LOGIN PASSWORD 'app' NOBYPASSRLS NOSUPERUSER NOCREATEDB NOCREATEROLE;

CREATE DATABASE wabot OWNER wabot_migrator;
CREATE DATABASE wabot_test OWNER wabot_migrator;

\c wabot
CREATE EXTENSION IF NOT EXISTS vector;
GRANT CONNECT ON DATABASE wabot TO wabot_app;
GRANT USAGE ON SCHEMA public TO wabot_app;
ALTER SCHEMA public OWNER TO wabot_migrator;

\c wabot_test
CREATE EXTENSION IF NOT EXISTS vector;
GRANT CONNECT ON DATABASE wabot_test TO wabot_app;
GRANT USAGE ON SCHEMA public TO wabot_app;
ALTER SCHEMA public OWNER TO wabot_migrator;
