# BookTrack hardening — 25 November 2026

The approved dashboard CSS, colors, imagery and responsive grid were preserved. This pass changes data integrity, error handling, account security and recovery. Local XAMPP settings and demo credentials were not converted to production settings.

## Approved backlog

| # | Item | Implementation and verification |
|---|---|---|
| 1 | Audio survives logging failures | Media rows, chapters and activity logging commit together. Only uncommitted uploads are removed on failure. Authenticated HTTP fault injection checks the old recording and orphan cleanup. |
| 2 | No orphan cover/PDF after failed save | Catalogue changes and logging share a transaction; failed saves remove their newly uploaded attachments. HTTP tests inject an activity-log failure with both files attached. |
| 3 | Strict deletion containment | Cleanup accepts exact upload folders and filenames, rejects traversal and links, and checks resolved directory boundaries. Traversal is exercised with a disposable file outside the allowed folder. |
| 4 | Database outage recovery | Only a missing users table leads to setup. Other connection/query failures return the common 503 response, no-store and Retry-After. An actual locked database is tested over HTTP. |
| 5 | Oversized forms | A size guard runs before sessions, authentication and CSRF; rejected requests return 413. Apache startup warnings are logged rather than breaking response headers. An oversized HTTP form is tested. Non-Apache PHP hosts must also disable startup error display. |
| 6 | Media replacement | A new PDF/recording resets progress and bookmarks for its format. PDF replacement clears its chapter markers; the audio editor validates submitted markers against the replacement recording. Old reader tabs must refresh. |
| 7 | Actual chapter bounds | The editor reads PDF page count using bundled PDF.js and audio duration using browser metadata, then validates markers before submission and on the server. Out-of-range legacy chapter buttons are disabled during playback. Metadata is staff-submitted, not an independent server decoder. |
| 8 | Permanent save failures | Authentication, stale-file, stale-progress and validation failures stop retries. Offline/timeouts retain only the newest pending place. Node tests cover failures and recovery. |
| 9 | Persistent unsaved warnings | A separate error banner survives page/playback status updates; beforeunload warns while saves are pending or failed. |
| 10 | Competing reading tabs/devices | Every write requires a file revision; progress also requires the last acknowledged progress version. A stale writer receives 409 and cannot overwrite a newer place. Unversioned old clients must refresh. |
| 11 | Password/session revocation | Password changes, email resets and librarian resets rotate per-account session generations, including pending two-step sign-in. Current self-service session is retained. Rules and HTTP tests verify revocation. |
| 12 | Concurrent member loan limits | App writers serialize across requests; MySQL also uses a named database lock. Separate workers attempting different books cannot exceed the member limit. |
| 13 | Atomic renewals | Validation, extension and activity log share the transaction and writer boundary. Concurrent renewals extend once. |
| 14 | Atomic return/undo | Conditional transitions and logging run together; a concurrent undo cannot resurrect a checked-in loan. |
| 15 | Concurrent holds | Placement/cancellation and queue processing are transactional and serialized. Duplicate placement and duplicate cancellation are tested with separate workers. |
| 16 | Catalogue edits/deletes vs borrowing | Catalogue updates/deletes share the circulation writer boundary, lock the record and recheck active stock. Ready holds count toward the minimum copy count. Concurrent copy edits and delete/borrow races are tested. |
| 17 | Lost librarian edits | Catalogue, member, media, featured selections and collection forms carry required version tokens. Stale or outdated editors must reload. |
| 18 | Restore vs normal activity | Requests share a recovery gate; restore obtains exclusive access before replacing data/files. A separate-worker test verifies normal requests cannot enter mid-recovery. |
| 19 | Filesystem rollback and messaging | Restore verifies attachment hashes and rollback copies. Failed rollback retains staging material and logs its location. Error messages do not claim a safety backup exists when creation failed. Tests cover a file-copy failure after a preceding file changed. |
| 20 | Encrypted TOTP secrets | AES-256-GCM uses a separate installation key. Existing plaintext secrets migrate transactionally. Tests cover account binding, tamper rejection, legacy migration and mismatched backup keys. |
| 21 | Encrypted backups/retention/separate copy | CLI authenticated encryption, verified separate copy, explicit retention preview/apply. Tests cover wrong passwords, truncation, multiple chunks, existing destinations, copy failure and safety-backup preservation. A real off-server destination and recovery drill remain host tasks. |
| 22 | Production readiness | Opt-in production profile enforces hidden debug output, disabled demo access, HTTPS and librarian 2FA. Environment overrides and a read-only readiness command are provided. Actual hosting credentials, TLS and email delivery are not configured or claimed as tested. |

## Local and production operation

Run `powershell -ExecutionPolicy Bypass -File tools/start-local.ps1 -CheckOnly` to check Apache/MySQL without restarting them. The site remains at `http://localhost/booktrack-v2/`.

For a real host, adapt `config.production.example.php` to `config.local.php`, or set `BOOKTRACK_ENV=production` with the matching private configuration. Do this on the production host, after librarian authenticator enrollment and replacement/deactivation of seeded accounts. Do not enable production mode on the local demo just to make the report green.

`BOOKTRACK_APP_URL`, `BOOKTRACK_DB_HOST`, `BOOKTRACK_DB_NAME`, `BOOKTRACK_DB_USER`, `BOOKTRACK_DB_PASSWORD`, `BOOKTRACK_KEY_FILE`, `BOOKTRACK_MAIL_DRIVER`, `BOOKTRACK_BACKUP_DIRECTORY` and `BOOKTRACK_BACKUP_COPY_DIRECTORY` can override their configuration values. SMTP details remain in private configuration; the readiness command never prints passwords.

```text
php tools/readiness.php
```

Exit 0 means its local configuration/account checks passed; exit 2 lists unmet checks. This command performs SELECT queries only and sends no email. It does not prove TLS, filesystem access restrictions, database grants, delivery, or off-server resilience.

## Key custody

The local installation key is generated under `storage/keys/` when needed. That directory is excluded from source control and covered by `storage/.htaccess`. Production should move the SAME key outside the web root and set `security.key_file` / `BOOKTRACK_KEY_FILE`, or supply its base64 representation through `BOOKTRACK_ENCRYPTION_KEY` in the host's secret store. Do not replace an existing key with a newly generated key: that would make enrolled authenticators unreadable.

Keep a separate, secure copy of that key. Data archives exclude it, identify the required key, and refuse a mismatched installation before restoring encrypted authenticator data. Windows administrators must set appropriate account ACLs; PHP chmod alone is not a Windows ACL policy.

## Backup commands

Set `BOOKTRACK_BACKUP_PASSWORD` privately in the current process environment or host secret store to a strong password of at least 16 characters. It is separate from the installation key. Do not put it in command arguments, source files or logs.

```text
php tools/backup.php create-encrypted
php tools/backup.php decrypt path/to/file.btenc path/to/new-file.tar
php tools/backup.php inspect path/to/new-file.tar
php tools/backup.php restore path/to/new-file.tar --confirm-replace-library
php tools/backup.php prune --keep=7 --days=30
php tools/backup.php prune --keep=7 --days=30 --apply
```

Encrypted export removes its temporary plaintext archive. If `backups.copy_directory` is configured, it copies the encrypted archive and verifies SHA-256 without overwriting an existing destination. A copy failure preserves the local encrypted archive. Configure a mounted off-server destination; a second directory on the same disk is not an off-server backup. No transfer or recurring schedule was created in this pass.

Retention is a preview unless `--apply` is supplied. It considers recognized manual TAR archives and encrypted exports, preserves the newest requested count plus the age window, and excludes safety, legacy/unclassified, pending and staged material. It does not prune the separate destination. Browser TAR downloads and automatically created safety TARs remain unencrypted; protect the backup directory and downloaded copies accordingly. Remove decrypted recovery files securely after the drill.

The encrypted format is `BTENC1`: a random 16-byte salt, PBKDF2-HMAC-SHA256 (600,000 iterations), and up to 1 MiB AES-256-GCM chunks with random nonces, authenticated header/index and a mandatory authenticated end marker. Wrong keys, altered chunks, reordering, truncation and trailing bytes are rejected.

## Recovery limits and outstanding acceptance

The recovery gate supports this single application server. Multiple servers need the same shared lock directory; direct SQL tools and external filesystem scripts must be stopped during restore. Long media streams can delay recovery; a busy library returns a retryable response. Database and filesystem commits cannot form one indivisible transaction across power loss. Retained recovery material and the safety archive are the recovery path if rollback itself fails. A controlled Windows filesystem write-denial drill verified retained rollback material and database state. Abrupt machine power-loss and the target host's own filesystem fault behavior remain outside this local rehearsal.

Still needed on the target host: real HTTPS/certificate validation, restricted database grants, new private credentials, librarian 2FA enrollment, real SMTP delivery, off-server backup/key recovery, monitoring, and multi-browser/accessibility acceptance. Browser access subsequently recovered. Fresh desktop, laptop, tablet and mobile checks preserved the ivory/white composition, showed no horizontal overflow or missing images, and produced no browser errors. The existing hosted service was identified through GitHub as the Render public demo; real-library SMTP/persistence requirements apply to a separate persistent deployment.

## Reproducible checks

```text
php tests/rules_test.php
php tests/chat_migration_test.php
php tests/enhancements_test.php
php tests/hardening_test.php
node tests/reading_state_test.mjs
```

With `BOOKTRACK_TEST_MYSQL=1`, the two PHP enhancement/hardening suites create and drop only their separately named disposable databases. HTTP suites require a disposable SQLite fixture under `tests/runtime`, exposed by a loopback server using the same fixture. Start that test server with `display_errors=0`, `display_startup_errors=0`, `post_max_size=12M`, `upload_max_filesize=8M`, then run the legacy, hardening and enhancement HTTP suites in that order. They mutate fixture records and must never run against the live library. GitHub Actions was updated to include these checks; remote CI was not run from this checkout.

Validated locally: **480 passing automated checks**, 117 PHP syntax checks, five JavaScript module syntax checks and four Python syntax checks. Apache/MySQL health passed after fixing the startup helper default-path calculation. Detailed counts and logs are indexed in `visual-review/hardening-audit.json`.

The CLI encrypted-export → decrypt → inspect round trip and retention preview also passed against the disposable fixture. Incomplete encrypted exports are staged under names excluded from retention and published only after encryption finishes. Disposable test directories were removed; the source checkpoint was retained.

## Hosted demo follow-up

The GitHub repository identifies the live demo as https://booktrack-demo.onrender.com. The Render profile intentionally keeps shared demo accounts, disables uploads/email and resets disposable data on startup. The readiness command now identifies this purpose separately from a persistent production library. Runtime SQLite data, locks and the encryption key are configured outside the document root; background and web operations use the same account.

Additional validation: 14 separate-installation/recovery-denial checks, five read-only health checks and 19 Render-compatible container HTTP checks passed locally. See DEPLOY.md for the source deployment, monitoring and mail acceptance commands. No real email was sent, no paid service was created, and no off-server provider was configured.
