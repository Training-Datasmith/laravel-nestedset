#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
IMAGE_TAG="laravel-nestedset-system-test:remote-test-2026-11-09"

docker_cmd() {
  if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
    sudo docker "$@"
  else
    docker "$@"
  fi
}

docker_cmd build -f "${ROOT}/docker/system-test/Dockerfile" -t "${IMAGE_TAG}" "${ROOT}/docker/system-test"

docker_cmd run --rm \
  -v "${ROOT}:/app" \
  -w /app \
  "${IMAGE_TAG}" \
  bash /app/scripts/system-test-inner.sh

echo 'System test passed.'
