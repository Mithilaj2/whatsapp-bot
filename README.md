# WhatsApp Bot SaaS

Multi-tenant WhatsApp bot and AI platform for Indian businesses, built on the
WhatsApp Cloud API as a Meta Tech Provider.

- `backend/`: Laravel 12 API and workers (PHP 8.3, PostgreSQL 16 + pgvector, Redis)
- `frontend/`: React + TypeScript dashboard (Vite, Tailwind)
- `docker/postgres/init.sql`: database roles, run once when Postgres is created

## Run it locally

```bash
docker compose up -d                       # Postgres (with pgvector) and Redis

cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --database=pgsql_migrator
php artisan serve                          # http://localhost:8000

cd ../frontend
npm install
npm run dev                                # http://localhost:5173, proxies /api to :8000
```

Tests run against a real Postgres (the `wabot_test` database), because row-level
security is part of what they check:

```bash
cd backend && php artisan test
```

## How tenant isolation works

Every business is a tenant. All tenant data lives in one shared schema with a
`tenant_id` column, and PostgreSQL row-level security enforces the boundary:

1. The app connects as `wabot_app`, a role that owns no tables and cannot
   bypass row-level security. Migrations run separately as `wabot_migrator`
   (`--database=pgsql_migrator`).
2. Each request names its business in the `X-Tenant-Id` header. The
   `tenant` middleware checks the signed-in user is an active member, then sets
   the Postgres setting `app.tenant_id`.
3. Every tenant table has a policy `tenant_id = app_current_tenant_id()`. With
   no tenant set, the tables look empty and inserts fail, so a forgotten
   `where` clause cannot leak another business's data.
4. Queue jobs and scripts use `TenantContext::run($tenantId, fn () => ...)`.

New tenant tables: add `tenant_id`, call
`RowLevelSecurity::enableForTenantTable('table')` in the migration, use the
`BelongsToTenant` trait on the model, and add a case to
`tests/Feature/TenantIsolationTest.php`.

## Auth

- Passwords are hashed with Argon2id.
- Sign-in returns a 15-minute access token (Sanctum) and a 30-day refresh token.
  Browsers get the refresh token only as an httpOnly cookie on `/api/auth`.
- Each refresh rotates the token. Replaying a used refresh token signs that
  session out everywhere.
- Roles per business: owner, admin, supervisor, agent, viewer, developer
  (`App\Enums\TenantRole`), checked with the `tenant.role:` route middleware.
- Logins, business changes and role changes go to `audit_logs`, which the app
  role can insert into but not update or delete.

## API so far

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/api/auth/register` | Creates the user and their first business |
| POST | `/api/auth/login` | |
| POST | `/api/auth/refresh` | Uses the refresh cookie |
| POST | `/api/auth/logout` | |
| GET | `/api/me` | User and the businesses they belong to |
| POST | `/api/tenants` | Another business, owned by the caller |
| GET, PATCH | `/api/tenant` | Current business (PATCH: owner, admin) |
| GET | `/api/members` | |
| PATCH | `/api/members/{id}` | Role, team, chat limit (owner, admin) |
| GET, POST | `/api/teams` | POST: owner, admin, supervisor |
| DELETE | `/api/teams/{id}` | Owner, admin, supervisor |

Routes below `/api/tenant`, `/api/members` and `/api/teams` need the `X-Tenant-Id` header.
