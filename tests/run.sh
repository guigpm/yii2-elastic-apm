#!/bin/sh
set -eu
cd "$(dirname "$0")"

for mode in console web asset disabled options preserve-web-401 preserve-web-404 \
    preserve-filtered preserve-disabled preserve-unhandled preserve-offline; do
    php -d error_reporting=E_ALL integration.php "$mode"
done

# Yii's console error handler deliberately exits with status 1.
status=0
output=$(php -d error_reporting=E_ALL integration.php preserve-console 2>&1) || status=$?
printf '%s\n' "$output"
test "$status" -eq 1
printf '%s\n' "$output" | grep -Fxq 'Preserved console handler passed.'
