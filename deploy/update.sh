#!/bin/bash
# Pull the latest main, rebuild, migrate and restart.
#   sudo bash deploy/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE=(docker compose -f deploy/docker-compose.yml --env-file deploy/.env)

git pull --ff-only
"${COMPOSE[@]}" build
"${COMPOSE[@]}" run --rm web php artisan migrate --database=pgsql_migrator --force
"${COMPOSE[@]}" up -d
"${COMPOSE[@]}" restart worker scheduler
