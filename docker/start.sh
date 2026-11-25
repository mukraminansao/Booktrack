#!/bin/sh
# Starts the BookTrack demo: a fresh SQLite library with the demo data, then Apache.
set -e
cd /var/www/html
export PORT="${PORT:-10000}"
case "$PORT" in ''|*[!0-9]*) echo 'PORT must be numeric' >&2; exit 1;; esac
if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then echo 'PORT is outside the supported range' >&2; exit 1; fi
echo "Listen ${PORT}" > /etc/apache2/ports.conf
php tests/make_sqlite.php /var/lib/booktrack/booktrack.sqlite
mkdir -p /var/lib/booktrack/locks /var/lib/booktrack/backups storage/keys storage/locks storage/backups uploads/audio uploads/pdfs uploads/covers
chown -R www-data:www-data /var/lib/booktrack storage uploads
# Migrations, locks and keys must be owned by the same account that handles requests.
su -s /bin/sh www-data -c 'php tools/prepare-demo.php'
# Cover art isn't in the repository: fetch the demo books' covers from Open Library in the
# background, so the site is up straight away and covers appear within a few seconds.
(su -s /bin/sh www-data -c 'php tools/fetch_demo_covers.php' || true) &
exec apache2-foreground
