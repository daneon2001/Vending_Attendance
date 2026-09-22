#!/usr/bin/env bash
set -euo pipefail
url=${1:?HTTPS beta origin required}
[[ "$url" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?$ ]] || exit 64
for endpoint in up ready; do
  # No redirects, insecure TLS, or fallback. HTTP status must be exactly 200.
  status=$(curl --silent --show-error --proto '=https' --connect-timeout 10 --max-time 30 --output /dev/null --write-out '%{http_code}' "$url/$endpoint")
  [[ "$status" == 200 ]] || { echo "Healthcheck failed: $endpoint" >&2; exit 1; }
done
