#!/usr/bin/env bash
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail_deprecation_output() {
  local label="$1"
  local file="$2"
  local stripped
  stripped="$(mktemp)"
  sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "${file}" >"${stripped}"
  if grep -E '^(PHP[[:space:]]+)?(Warning|Deprecated|Notice):' "${stripped}" >/dev/null; then
    echo "${label}: output contains Warning, Deprecated, or Notice" >&2
    grep -E '^(PHP[[:space:]]+)?(Warning|Deprecated|Notice):' "${stripped}" >&2 || true
    rm -f "${stripped}"
    exit 1
  fi
  rm -f "${stripped}"
}

php -v | tee /tmp/php-version.txt
php -r 'if (version_compare(PHP_VERSION, "8.0.0", "<")) { fwrite(STDERR, "PHP floor not met\n"); exit(1);}'

lint_out="$(mktemp)"
set +e
find src tests examples scripts -name '*.php' -print0 | xargs -0 -r php -d error_reporting=32767 -l >"${lint_out}" 2>&1
lint_status=$?
set -e
cat "${lint_out}"
fail_deprecation_output "php -l" "${lint_out}"
if [[ ${lint_status} -ne 0 ]]; then
  exit "${lint_status}"
fi
rm -f "${lint_out}"

php -d error_reporting=32767 scripts/check-apt-policy.php

COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
git config --global --add safe.directory "${ROOT}" >/dev/null 2>&1 || true

curl -fsSL -o /tmp/composer.phar "${COMPOSER_URL}"
echo "${COMPOSER_SHA256} /tmp/composer.phar" | sha256sum -c -

composer_out="$(mktemp)"
set +e
php -d error_reporting=32767 /tmp/composer.phar update --no-interaction --prefer-dist --no-progress >"${composer_out}" 2>&1
composer_status=$?
set -e
cat "${composer_out}"
fail_deprecation_output "composer update" "${composer_out}"
if [[ ${composer_status} -ne 0 ]]; then
  rm -f "${composer_out}"
  exit "${composer_status}"
fi
rm -f "${composer_out}"

php -d error_reporting=32767 scripts/check-cluster-pins.php

audit_out="$(mktemp)"
set +e
php -d error_reporting=32767 /tmp/composer.phar audit --no-interaction --abandoned=report >"${audit_out}" 2>&1
audit_status=$?
set -e
cat "${audit_out}"
fail_deprecation_output "composer audit" "${audit_out}"
if [[ ${audit_status} -ne 0 ]]; then
  exit "${audit_status}"
fi
if ! grep -Fxq 'No security vulnerability advisories found.' "${audit_out}"; then
  echo 'composer audit: missing exact success line' >&2
  exit 1
fi
rm -f "${audit_out}"

php -d error_reporting=32767 scripts/check-rename.php

junit_file="/tmp/phpunit.junit.xml"
phpunit_out="$(mktemp)"
set +e
php -d error_reporting=32767 vendor/bin/phpunit --colors=never --no-coverage --do-not-cache-result \
  --log-junit "${junit_file}" >"${phpunit_out}" 2>&1
phpunit_status=$?
set -e
cat "${phpunit_out}"
fail_deprecation_output "phpunit" "${phpunit_out}"
if [[ ${phpunit_status} -ne 0 ]]; then
  exit "${phpunit_status}"
fi

EXPECTED_LINE="$(tr -d '\r' < "${ROOT}/scripts/expected-phpunit-ok.txt" | head -n 1)"
if [[ -z "${EXPECTED_LINE}" ]]; then
  echo 'Missing scripts/expected-phpunit-ok.txt' >&2
  exit 1
fi
if ! grep -Fxq "${EXPECTED_LINE}" <(sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "${phpunit_out}"); then
  echo "phpunit: expected exact line: ${EXPECTED_LINE}" >&2
  exit 1
fi
rm -f "${phpunit_out}"

php -d error_reporting=32767 scripts/check-skips.php "${junit_file}"

echo 'system-test-inner: all steps passed.'
