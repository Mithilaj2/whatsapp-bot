#!/bin/bash
# Run an artisan command on the server, e.g.:
#   sudo bash deploy/artisan.sh whatsapp:connect <business-id> --waba=... --phone-number-id=...
set -euo pipefail
cd "$(dirname "$0")/.."
exec docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec web php artisan "$@"
