#!/usr/bin/env bash
# Starts ClickHouse for the integration tests, with a test CA and a server certificate for 127.0.0.1 and localhost.
set -euo pipefail
cd "$(dirname "$0")"

certs=.certs
if [ ! -f "$certs/ca.pem" ]; then
    mkdir -p "$certs"
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=Kipchak Test CA" \
        -keyout "$certs/ca-key.pem" -out "$certs/ca.pem" 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" \
        -keyout "$certs/server-key.pem" -out "$certs/server.csr" 2>/dev/null
    printf 'subjectAltName=DNS:localhost,DNS:clickhouse,IP:127.0.0.1\n' > "$certs/san.ext"
    openssl x509 -req -in "$certs/server.csr" -CA "$certs/ca.pem" -CAkey "$certs/ca-key.pem" -CAcreateserial \
        -days 3650 -extfile "$certs/san.ext" -out "$certs/server.pem" 2>/dev/null
    # A CA that did not sign the server's certificate, for the verification tests.
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=Other CA" \
        -keyout "$certs/other-ca-key.pem" -out "$certs/other-ca.pem" 2>/dev/null
    chmod 644 "$certs"/*.pem
fi

docker compose up -d --wait 2>/dev/null || docker compose up -d

for _ in $(seq 1 60); do
    if curl -sf --cacert "$certs/ca.pem" https://127.0.0.1:58443/ping >/dev/null 2>&1; then
        echo "ClickHouse is ready."
        exit 0
    fi
    sleep 1
done
echo "ClickHouse did not start." >&2
docker compose logs clickhouse | tail -30 >&2
exit 1
