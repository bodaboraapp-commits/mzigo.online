#!/bin/sh
# Mzigo.online container entrypoint: wait for MariaDB, install/upgrade schema, serve.
set -e

if [ -n "$DB_HOST" ]; then
  echo "Mzigo.online: waiting for database ${DB_HOST}:${DB_PORT:-3306} ..."
  i=0
  until php -r '
      try {
        new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . (getenv("DB_PORT") ?: "3306"),
                getenv("DB_USER") ?: "root", getenv("DB_PASS") ?: "");
        exit(0);
      } catch (Throwable $e) { exit(1); }
    '; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
      echo "Mzigo.online: database did not become ready in time" >&2
      exit 1
    fi
    sleep 2
  done

  echo "Mzigo.online: applying schema + seed ..."
  php _server/db/install.php
fi

exec "$@"
