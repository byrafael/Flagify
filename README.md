# Flagify

Flagify is a plain-PHP REST API for project-scoped feature flags, customer clients, per-client overrides, and runtime config resolution.

In this API, a `client` is a customer account inside a project, for example `acme-inc`.

## Runtime

- PHP 8.3+
- PDO
- MySQL 8+
- Apache/cPanel compatible
- No Composer runtime dependency

## Environment

Copy `.env.example` to `.env` and set:

- `DB_DRIVER`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `FLAGIFY_BOOTSTRAP_KEY`

## Local Run

1. Create the MySQL database.
2. Run:

```bash
php bin/migrate.php
```

3. Start the app:

```bash
php -S 127.0.0.1:8080 index.php
```

## cPanel Deployment

Git deployment is defined in [/.cpanel.yml](/Users/rsoley/Dev/oss/Flagify/.cpanel.yml).

- Target path: `/home/rsrdev/flagify.rsrdev.com/`
- Requests rewrite through [/.htaccess](/Users/rsoley/Dev/oss/Flagify/.htaccess)

## API Docs

The API reference now lives in [openapi.yaml](/Users/rsoley/Dev/oss/Flagify/openapi.yaml).

The Postman docs are available in:

- [docs/postman/Flagify.postman_collection.json](/Users/rsoley/Dev/oss/Flagify/docs/postman/Flagify.postman_collection.json)
- [docs/postman/Flagify.local.postman_environment.json](/Users/rsoley/Dev/oss/Flagify/docs/postman/Flagify.local.postman_environment.json)

## Notes

- Root administrative access uses `FLAGIFY_BOOTSTRAP_KEY`.
- License: [MIT](/Users/rsoley/Dev/oss/Flagify/LICENSE)
- This is a side project I needed, which is mainly maintained by AI agents.
