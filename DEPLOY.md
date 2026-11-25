# Updating hosted BookTrack

The existing public demo is **https://booktrack-demo.onrender.com**, linked from **https://github.com/mukraminansao/Booktrack**. Its source contains `Dockerfile`, `render.yaml` and the `docker/` configuration. The saved InfinityFree settings under local `deploy/` belong to an older deployment path and must not be copied into Render.

## Render public demo

The container uses PHP/Apache and a private SQLite database under `/var/lib/booktrack`. It rebuilds disposable demo records on startup, retains one-click demo accounts, blocks uploads and sends no email. Do not enter real member information or persistent library records in this portfolio demo.

The deployment update preserves that mode. Real SMTP credentials and private member passwords are unnecessary for a shared demonstration. A real library needs a separate persistent deployment and the production profile.

Before publishing:

1. Run the application tests and build the Docker image.
2. Start an isolated container and run `tests/container_smoke_test.py` against its loopback URL. It checks health, login, the warm dashboard, protected paths, assets and Secure cookies behind the trusted proxy.
3. Run `php tools/readiness.php` inside the container as `www-data`. The report identifies the public-demo purpose and does not claim production-library readiness.
4. Publish a review branch to GitHub and wait for Rules, HTTP/database and Demo container checks. The Blueprint declares automatic deployment; verify Render actually completes deployment before calling the update live.
5. Open the hosted demo, sign in with its member button, check desktop/mobile composition, and verify `/health.php` plus private-path restrictions on that origin.

Startup creates schema, locks and the encryption key as `www-data`, so background cover fetching and web requests use compatible permissions. Runtime keys, the database and backups stay outside the web root. Optional cover downloads leave generated covers when unavailable.

The image validates Render's numeric PORT, defaults to 10000 and declares `/health.php` as its health check. The Apache configuration trusts Render's edge-provided `X-Forwarded-Proto` to set Secure session cookies. Do not reuse this trust rule on an exposed origin without a trusted reverse proxy.

## Read-only acceptance and monitoring

```text
php tools/check-host.php https://booktrack-demo.onrender.com
php tools/check-host.php http://localhost/booktrack-v2 --allow-local
```

This verifies TLS using curl, sign-in/health responses, security headers and known private paths. It does not log in, change records, send email or bypass certificate errors. A missing file returning 404 does not independently prove every directory rule.

`/health.php` returns only `{"status":"ok"}` or `{"status":"unavailable"}` with 200/503. It supports HEAD, rejects writes, creates no session and uses SELECT queries without migrations, resets or reminders. A chosen external monitor can use it; these tools do not create a recurring service or paid plan.

## Persistent production library

Use `config.production.example.php` as the private `config.local.php` template on a persistent PHP/MySQL or secured SQLite host. Set the actual document root in `security.web_root` when it differs from the application folder. Provision HTTPS, restricted database/file access, the original installation key outside the web root, private account credentials, librarian authenticator enrollment, SMTP and off-server backups. The production profile intentionally disables one-click demo access; do not enable it on the shared demonstration.

```text
php tools/readiness.php
php tools/check-mail.php
php tools/check-mail.php --send --to=your-own-mailbox@example.com
php tools/backup.php create-encrypted
```

Mail is sent only with the explicit --send flag and recipient. SMTP acceptance does not prove inbox delivery: confirm the reference in the recipient mailbox. Never put passwords in command arguments, source control or public issues.

Supply the backup password privately through BOOKTRACK_BACKUP_PASSWORD. Configure backups.copy_directory for an actual off-server mount and rehearse recovery with the separately stored original installation key. `tests/recovery_drill_test.php` restores fake records into a second local installation and exercises OS rollback denial. That test does not claim a remote provider has been configured.

## Shared Apache hosting package

```text
powershell -NoProfile -ExecutionPolicy Bypass -File tools/build-release.ps1 -Mode Production
```

The ZIP under deploy/releases has a SHA-256 manifest and excludes private configuration, keys, saved mail, uploaded library files, backups, reviews and test data. Local settings are untouched. Its production profile needs valid host configuration before serving visitors. The public installer is excluded: prepare or recover the target database privately before publishing. Use GitHub/Docker source for the existing Render service rather than uploading this ZIP into it.

For an explicitly disposable shared-hosting demo, -Mode Demo preserves demo accounts and disables debug output/mail. Keep it separate from a real library database.

## Rollback

Keep the previous working commit available. A failed build must not advance the deployed branch. A failed runtime check calls for the host's previous deployment/source, preserving private configuration and runtime data. Source rollback differs from restoring library records. The public demo resets intentionally; a persistent library needs a verified data backup before migrations or recovery.
