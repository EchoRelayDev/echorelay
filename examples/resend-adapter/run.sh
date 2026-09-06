#!/usr/bin/env bash
# Sends one sync request and one async request through the endpoints
# setup.sh created, then polls the async request's receipt.
#
# Required env:
#   API_HOST, API_TOKEN     management API (same as setup.sh — used here only
#                           to poll the receipt)
#   PROJECT_SLUG            the project slug; the line serves at
#                           https://{slug}.echorelay.cloud/{lineKey}
#   RELAY_API_KEY           the inbound key setup.sh minted ("plaintext" in
#                           its /keys response)
#
# Optional env:
#   LINE_KEY      (default: relay-example)
#   RELAY_DOMAIN  (default: echorelay.cloud)
#   TO            (default: delivered@resend.dev — Resend's sandbox address;
#                 bounced@resend.dev is the other one it defines)
set -euo pipefail

: "${API_HOST:?}"; : "${API_TOKEN:?}"; : "${PROJECT_SLUG:?}"; : "${RELAY_API_KEY:?}"
LINE_KEY="${LINE_KEY:-relay-example}"
RELAY_DOMAIN="${RELAY_DOMAIN:-echorelay.cloud}"
TO="${TO:-delivered@resend.dev}"
BASE="https://${PROJECT_SLUG}.${RELAY_DOMAIN}/${LINE_KEY}"

curl -sf -X POST "${BASE}/resend-sync" \
  -H "Authorization: Bearer ${RELAY_API_KEY}" -H "Content-Type: application/json" \
  -d "{\"recipients\": \"${TO}\", \"title\": \"Quickstart\", \"note\": \"sent through the relay\"}"
echo

async_reply="$(curl -sf -X POST "${BASE}/resend-async" \
  -H "Authorization: Bearer ${RELAY_API_KEY}" -H "Content-Type: application/json" \
  -d "{\"recipients\": \"${TO}\", \"title\": \"Quickstart (queued)\", \"note\": \"queued through the relay\"}")"
echo "${async_reply}"

request_id="$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['requestId'])" "${async_reply}")"

# Poll until a receipt appears. An accepted async request is delivered a moment
# later, so asking once always shows an empty list and reads as "nothing
# happened" — which is the opposite of what this example exists to demonstrate.
deadline=$((SECONDS + 30))
while :; do
    receipts="$(curl -sf "${API_HOST}/requests/${request_id}/receipts" \
        -H "Authorization: Bearer ${API_TOKEN}")"
    count="$(python3 -c "import json,sys; print(len(json.loads(sys.argv[1])['receipts']))" "${receipts}")"
    [ "${count}" -gt 0 ] && break
    if [ "${SECONDS}" -ge "${deadline}" ]; then
        echo "${receipts}"
        echo "no receipt after 30s — the request was accepted, so check delivery in the panel" >&2
        exit 1
    fi
    sleep 2
done
echo "${receipts}"
