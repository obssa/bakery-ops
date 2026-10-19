#!/usr/bin/env bash
# Prepare the database service container for the migrations (MariaDB 10.11 in CI, same as the live host;
# the flag is harmless there). MySQL 8 ships with binary logging on, and then
# CREATE TRIGGER needs SUPER or log_bin_trust_function_creators=1 (error 1419); the append-only triggers on
# audit_log / stock_movements / order_events are created by migrations as the app user. GitHub service
# containers take no command-line flags, so set it as root at run time. compose.yml passes the flag directly.
set -euo pipefail
host="${DB_HOST:-127.0.0.1}"; port="${DB_PORT:-3306}"; root_pw="${MYSQL_ROOT_PASSWORD:-root}"
mysql --host="$host" --port="$port" --protocol=TCP -uroot -p"$root_pw" \
  -e "SET GLOBAL log_bin_trust_function_creators = 1; SELECT @@log_bin_trust_function_creators AS trust_function_creators;"
