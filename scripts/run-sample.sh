#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-5080}"
SECRETS_FILE="${FLAGPOST_SECRETS_FILE:-$HOME/.config/flagpost/fly.env}"

if [[ -z "${Flagpost__ApiKey:-}" && -f "$SECRETS_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$SECRETS_FILE"
    export Flagpost__ApiKey="${FLAGPOST_ENV_KEY:-}"
fi

if [[ -z "${Flagpost__ApiKey:-}" ]]; then
    cat >&2 <<'EOF'
Set Flagpost__ApiKey to an environment key (fp_prod_...) first, for example:

  export Flagpost__ApiKey="fp_prod_..."

Or create ~/.config/flagpost/fly.env with FLAGPOST_ENV_KEY=... .
EOF
    exit 1
fi

echo "Starting the sample at http://localhost:${PORT}/?user=alice"
echo "Press Ctrl+C to stop."

exec docker run --rm \
    -u "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -e DOTNET_NOLOGO=1 \
    -e "Flagpost__ApiKey=${Flagpost__ApiKey}" \
    -e "Flagpost__BaseUrl=${Flagpost__BaseUrl:-https://flagpost-api.fly.dev}" \
    -e "Flagpost__PollIntervalSeconds=${Flagpost__PollIntervalSeconds:-5}" \
    -e "Flagpost__HttpTimeoutSeconds=${Flagpost__HttpTimeoutSeconds:-30}" \
    -e "ASPNETCORE_URLS=http://0.0.0.0:5080" \
    -p "${PORT}:5080" \
    -v "${ROOT}:/src" \
    -w /src/samples/dotnet-webapp \
    mcr.microsoft.com/dotnet/sdk:10.0 \
    dotnet run --urls http://0.0.0.0:5080
