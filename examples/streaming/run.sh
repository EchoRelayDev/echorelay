#!/usr/bin/env bash
# Creates the streaming endpoint in endpoint.json, then sends one request
# through it. deliveryMode: "stream" — the outgoing request is reshaped, the
# target's answer never is; the relay only feeds it through as it arrives.
#
# Required env:
#   API_HOST, API_TOKEN            management API (same as examples/resend-adapter)
#   PROJECT_SLUG, RELAY_API_KEY    data-plane relay (same as examples/resend-adapter)
#   STREAM_TARGET_URL              your own streaming HTTP endpoint
#   STREAM_TARGET_API_KEY          its bearer credential
#
# Optional env:
#   LINE_KEY      (default: relay-example)
#   RELAY_DOMAIN  (default: echorelay.cloud)
set -euo pipefail

: "${API_HOST:?}"; : "${API_TOKEN:?}"; : "${PROJECT_SLUG:?}"; : "${RELAY_API_KEY:?}"
: "${STREAM_TARGET_URL:?}"; : "${STREAM_TARGET_API_KEY:?}"
LINE_KEY="${LINE_KEY:-relay-example}"
RELAY_DOMAIN="${RELAY_DOMAIN:-echorelay.cloud}"
DIR="$(cd "$(dirname "$0")" && pwd)"

# Piped, never written out: the filled-in document holds your target's
# credential, and a copy of it in a temporary directory outlives the run.
python3 "${DIR}/../fill-placeholders.py" "${DIR}/endpoint.json" \
    STREAM_TARGET_URL_PLACEHOLDER=STREAM_TARGET_URL \
    STREAM_TARGET_API_KEY_PLACEHOLDER=STREAM_TARGET_API_KEY \
| curl -sf -X POST "${API_HOST}/lines/${LINE_KEY}/endpoints" \
  -H "Authorization: Bearer ${API_TOKEN}" -H "Content-Type: application/json" \
  -d @-
echo

curl -sf -N -X POST "https://${PROJECT_SLUG}.${RELAY_DOMAIN}/${LINE_KEY}/stream" \
  -H "Authorization: Bearer ${RELAY_API_KEY}" -H "Content-Type: application/json" \
  -d '{}'
