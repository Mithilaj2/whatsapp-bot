#!/bin/bash
# First-time setup on a fresh Ubuntu 24.04 server. Run from the repo root:
#   sudo bash deploy/setup.sh
# Safe to run again: it keeps an existing deploy/.env.
set -euo pipefail

cd "$(dirname "$0")/.."
ENV_FILE=deploy/.env
COMPOSE=(docker compose -f deploy/docker-compose.yml --env-file "$ENV_FILE")

if ! command -v docker >/dev/null; then
    echo "Installing Docker..."
    curl -fsSL https://get.docker.com | sh
fi

rand() { openssl rand -hex "${1:-24}"; }

if [ ! -f "$ENV_FILE" ]; then
    read -rp "Domain for this server (e.g. app.example.com): " DOMAIN
    read -rp "Meta App ID: " META_APP_ID
    read -rsp "Meta App Secret (hidden): " META_APP_SECRET; echo
    VERIFY_TOKEN=$(rand 16)

    umask 077
    cat > "$ENV_FILE" <<ENV
APP_NAME="WhatsApp Bot"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://${DOMAIN}
FRONTEND_URL=https://${DOMAIN}
SERVER_NAME=${DOMAIN}
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=wabot
DB_USERNAME=wabot_app
DB_PASSWORD=$(rand)
DB_MIGRATOR_USERNAME=wabot_migrator
DB_MIGRATOR_PASSWORD=$(rand)
DB_APP_ROLE=wabot_app
POSTGRES_PASSWORD=$(rand)

REDIS_CLIENT=phpredis
REDIS_HOST=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

HASH_DRIVER=argon2id
AUTH_ACCESS_TOKEN_TTL=15
AUTH_REFRESH_TOKEN_TTL_DAYS=30

META_APP_ID=${META_APP_ID}
META_APP_SECRET=${META_APP_SECRET}
META_WEBHOOK_VERIFY_TOKEN=${VERIFY_TOKEN}
META_GRAPH_VERSION=v25.0
ENV
    echo "Wrote $ENV_FILE (keep it private; it holds the secrets)."
fi

echo "Building and starting..."
"${COMPOSE[@]}" up -d --build
"${COMPOSE[@]}" run --rm web php artisan migrate --database=pgsql_migrator --force

DOMAIN=$(grep '^SERVER_NAME=' "$ENV_FILE" | cut -d= -f2)
VERIFY_TOKEN=$(grep '^META_WEBHOOK_VERIFY_TOKEN=' "$ENV_FILE" | cut -d= -f2)
cat <<DONE

Done. Open https://${DOMAIN} and create your account.

In your Meta app (WhatsApp → Configuration → Webhook):
  Callback URL:  https://${DOMAIN}/api/webhooks/whatsapp
  Verify token:  ${VERIFY_TOKEN}
  Then subscribe to the "messages" field.

To connect the test number to your business:
  sudo bash deploy/artisan.sh whatsapp:connect <business-id> --waba=<waba-id> --phone-number-id=<phone-number-id>
DONE
