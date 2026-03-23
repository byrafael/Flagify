# Flagify

Flagify is a lightweight PHP 8.3 REST API for project-scoped feature flags, registered clients, per-client overrides, and API-key-based runtime resolution.

## Stack

- PHP 8.3+
- Plain PHP runtime
- PDO
- MySQL 8+ for the primary runtime database
- Apache/cPanel compatible entrypoint and rewrite rules

The production runtime does not require Composer or a `vendor/` directory.

## Directory Layout

- `index.php` root entrypoint for Apache/cPanel
- `public/` alternate entrypoint
- `src/Auth/` API key auth and authorization
- `src/Http/NativeApplication.php` native router and request handler
- `src/Repository/` PDO repositories
- `src/Service/` flag validation and runtime config resolution
- `database/migrations/` SQL migrations
- `.cpanel.yml` cPanel Git deployment file

## Environment Variables

Copy `.env.example` to `.env` and set:

- `APP_DEBUG`
- `DB_DRIVER`
  - `mysql` for local/runtime usage
  - `sqlite` is supported by the test harness
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `FLAGIFY_BOOTSTRAP_KEY`

## Local Setup

1. Create a MySQL database:

```sql
CREATE DATABASE flagify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Apply the migration in [`database/migrations/001_initial_schema.sql`](/Users/rsoley/Dev/oss/Flagify/database/migrations/001_initial_schema.sql):

```bash
php bin/migrate.php
```

3. Start the API:

```bash
php -S 127.0.0.1:8080 index.php
```

## cPanel Deployment

The repository now includes [`.cpanel.yml`](/Users/rsoley/Dev/oss/Flagify/.cpanel.yml) for cPanel Git deployment.

- Deployment target: `/home/rsrdev/flagify.rsrdev.com/`
- The deployment copies `index.php`, `autoload.php`, `.htaccess`, `src/`, `config/`, `database/`, `bin/`, and `public/`.
- Apache rewrites are configured in [`.htaccess`](/Users/rsoley/Dev/oss/Flagify/.htaccess) so `/api/v1/...` requests route through [`index.php`](/Users/rsoley/Dev/oss/Flagify/index.php).
- The cPanel deployment file follows the required checked-in top-level `.cpanel.yml` model described by cPanel’s Git deployment guide.

## Auth Model

Flagify supports bearer-token API keys only.

- Root access comes from `FLAGIFY_BOOTSTRAP_KEY`.
- DB-backed keys store only `prefix` and `secret_hash`.
- Plaintext secrets are returned once on creation.
- Revoked, expired, deleted-project, or inactive-client keys fail with `401`.

Supported DB-backed key kinds:

- `project_admin`
- `project_read`
- `client_runtime`

## Flag Types

- `boolean`
  - `default_value` must be `true` or `false`
  - `options` must be `null`
- `select`
  - `default_value` must be one string from `options`
  - `options` must be a unique non-empty string array
- `multi_select`
  - `default_value` must be an array of unique strings from `options`
  - submitted order is preserved

## Runtime Resolution

Resolution is bulk-first:

1. Load active flags for the project.
2. Load overrides for the target client.
3. Prefer override values over defaults.
4. Return values keyed by flag key.

Archived flags are excluded from runtime responses.

## Curl Examples

Set shared variables:

```bash
ROOT_KEY="change-me-bootstrap-key"
BASE_URL="http://127.0.0.1:8080"
```

Bootstrap a project:

```bash
curl -sS \
  -H "Authorization: Bearer ${ROOT_KEY}" \
  -H "Content-Type: application/json" \
  -X POST "${BASE_URL}/api/v1/projects" \
  -d '{
    "name": "Example",
    "slug": "example",
    "description": "Example project"
  }'
```

Create a flag:

```bash
curl -sS \
  -H "Authorization: Bearer ${PROJECT_ADMIN_KEY}" \
  -H "Content-Type: application/json" \
  -X POST "${BASE_URL}/api/v1/projects/${PROJECT_ID}/flags" \
  -d '{
    "key": "theme",
    "name": "Theme",
    "type": "select",
    "default_value": "dark",
    "options": ["dark", "light"]
  }'
```

Create a client:

```bash
curl -sS \
  -H "Authorization: Bearer ${PROJECT_ADMIN_KEY}" \
  -H "Content-Type: application/json" \
  -X POST "${BASE_URL}/api/v1/projects/${PROJECT_ID}/clients" \
  -d '{
    "key": "ios-app",
    "name": "iOS App",
    "metadata": {"platform": "ios"}
  }'
```

Create a runtime key:

```bash
curl -sS \
  -H "Authorization: Bearer ${PROJECT_ADMIN_KEY}" \
  -H "Content-Type: application/json" \
  -X POST "${BASE_URL}/api/v1/keys" \
  -d "{
    \"kind\": \"client_runtime\",
    \"name\": \"iOS Runtime\",
    \"project_id\": \"${PROJECT_ID}\",
    \"client_id\": \"${CLIENT_ID}\"
  }"
```

Assign an override:

```bash
curl -sS \
  -H "Authorization: Bearer ${PROJECT_ADMIN_KEY}" \
  -H "Content-Type: application/json" \
  -X PUT "${BASE_URL}/api/v1/projects/${PROJECT_ID}/clients/${CLIENT_ID}/flags/${FLAG_ID}/override" \
  -d '{
    "value": "light"
  }'
```

Fetch runtime config with a client runtime key:

```bash
curl -sS \
  -H "Authorization: Bearer ${CLIENT_RUNTIME_KEY}" \
  "${BASE_URL}/api/v1/runtime/config"
```

Fetch runtime config with a project read/admin key:

```bash
curl -sS \
  -H "Authorization: Bearer ${PROJECT_READ_KEY}" \
  "${BASE_URL}/api/v1/runtime/projects/${PROJECT_ID}/clients/ios-app/config"
```

## Response Notes

- All responses are JSON.
- Timestamps are returned as UTC ISO-8601.
- Errors use:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Request validation failed",
    "details": []
  }
}
```

## Notes

- The runtime path no longer requires Composer.
- This environment did not have `php` installed, so I could not execute the app or migrations here.
