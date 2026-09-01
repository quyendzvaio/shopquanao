#!/usr/bin/env bash
set -euo pipefail

endpoint="${GLANCE_MCP_URL:-https://ember.ailooks.glance.com/mcp}"
if [[ "${GLANCE_ENABLED:-false}" != "true" || "${GLANCE_PROVIDER_MODE:-}" != "live" ]]; then
  echo "GLANCE_LIVE_CONNECTIVITY=BLOCKED (set GLANCE_ENABLED=true and GLANCE_PROVIDER_MODE=live)" >&2
  exit 2
fi
echo "Starting documented remote MCP bridge for ${endpoint}" >&2
exec npx --yes mcp-remote "${endpoint}"
