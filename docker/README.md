# BookTrack public demo container

Live demo: https://booktrack-demo.onrender.com. The GitHub Blueprint uses this Dockerfile and /health.php. See [DEPLOY.md](../DEPLOY.md) for deployment and acceptance checks.

This image runs PHP/Apache with a private SQLite library. It is a disposable portfolio demo: records rebuild on every startup, demo accounts remain accessible, uploads are blocked and email is off. It is not the persistence configuration for a real library.

Startup seeds the database, adjusts runtime ownership, and initializes schema, locks and the encryption key as www-data before serving requests. The same user optionally downloads missing covers in the background. Keys, SQLite data and operation locks live under /var/lib/booktrack, outside the document root. Optional cover failures retain generated artwork.

The numeric PORT defaults to 10000. Apache accepts Render's edge-provided X-Forwarded-Proto only in this proxy configuration so browser sessions receive Secure cookies on HTTPS. Do not copy that trust rule onto an unproxied public origin.

The Docker context excludes active local settings, uploads, keys, backups, saved mail, runtime tests and review artifacts. The image contains the current application plus the protected CLI demo seeder; private data is never part of the release image.

The Demo container CI job builds this image, exercises health/private-file restrictions, signs in to the warm dashboard and runs the deployment-purpose readiness report. Render deployment still needs to be observed after publishing; a successful CI job alone does not prove the live service updated.
