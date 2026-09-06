#!/usr/bin/env bash
# Creates the line and the two endpoints in endpoints.json (sync + async)
# against a real EchoRelay account, then mints the inbound relay key run.sh
# calls them with.
#
# Required env:
#   API_HOST        management API host, e.g. https://api.echorelay.dev
#   API_TOKEN       management API token (er_mcp_..., project- or account-scoped)
#   RESEND_API_KEY  Resend API key (re_...)
#   RESEND_FROM     a sender your Resend account is allowed to send from
#
# Optional env:
#   LINE_KEY   (default: relay-example)
set -euo pipefail

: "${API_HOST:?}"; : "${API_TOKEN:?}"; : "${RESEND_API_KEY:?}"; : "${RESEND_FROM:?}"
LINE_KEY="${LINE_KEY:-relay-example}"
DIR="$(cd "$(dirname "$0")" && pwd)"

curl -sf -X POST "${API_HOST}/lines" \
  -H "Authorization: Bearer ${API_TOKEN}" -H "Content-Type: application/json" \
  -d "{\"name\": \"Resend adapter example\", \"lineKey\": \"${LINE_KEY}\"}"
echo

# Piped, never written out: the filled-in file holds your Resend key, and a
# copy of it left in a temporary directory outlives the run.
python3 "${DIR}/../fill-placeholders.py" "${DIR}/endpoints.json" \
    RESEND_API_KEY_PLACEHOLDER=RESEND_API_KEY \
    RESEND_FROM_PLACEHOLDER=RESEND_FROM \
| python3 -c "
import json, sys
for endpoint in json.load(sys.stdin):
    print(json.dumps(endpoint))
" | while IFS= read -r endpoint; do
  curl -sf -X POST "${API_HOST}/lines/${LINE_KEY}/endpoints" \
    -H "Authorization: Bearer ${API_TOKEN}" -H "Content-Type: application/json" \
    -d "${endpoint}"
  echo
done

curl -sf -X POST "${API_HOST}/keys" \
  -H "Authorization: Bearer ${API_TOKEN}" -H "Content-Type: application/json" \
  -d '{"name": "resend-adapter-example", "mode": "live"}'
echo
