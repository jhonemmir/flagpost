#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -e .env ]; then
  echo ".env already exists; refusing to overwrite it." >&2
  exit 1
fi

umask 077

app_key="base64:$(openssl rand -base64 32)"
admin_key="$(openssl rand -hex 32)"
db_password="$(openssl rand -hex 24)"

cat > .env <<EOF
# Local development only. Never commit this file.
APP_KEY=$app_key
FLAGPOST_ADMIN_KEY=$admin_key
POSTGRES_PASSWORD=$db_password
EOF

echo "Wrote .env with a fresh APP_KEY, FLAGPOST_ADMIN_KEY and POSTGRES_PASSWORD."
