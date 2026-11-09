FROM php:8.0.30-cli-bullseye@sha256:5a3688ea73ab41ba0242172fed98952e83b6d202536729e33b58dc429db2db57

RUN set -eux; \
    printf '%s\n' \
        'deb [check-valid-until=no] https://archive.debian.org/debian bullseye main' \
        'deb [check-valid-until=no] https://archive.debian.org/debian-security bullseye-security main' \
        'deb [check-valid-until=no] https://archive.debian.org/debian bullseye-updates main' \
        > /etc/apt/sources.list; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app
