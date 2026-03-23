# Flagify

Flagify is a plain-PHP REST API for self-hosted feature management.

It now supports:
- projects
- environments per project
- clients per project
- flags with variants, prerequisites, expiry, and stale-state tracking
- reusable segments
- per-environment targeting rules
- per-client overrides
- evaluation event capture
- runtime resolution and local-evaluation snapshots

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

The migrator now records applied files in `schema_migrations`, so reruns skip already-applied migrations.

3. Start the app:

```bash
php -S 127.0.0.1:8080 index.php
```

## Default Project Shape

Each new project gets three environments automatically:
- `development`
- `staging`
- `production`

`production` is the default environment used when runtime requests do not specify one.

## Core Concepts

### Segments

Segments are reusable rule sets based on client attributes such as:
- `key`
- `name`
- `metadata.plan`
- `metadata.country`
- `metadata.app_version`

### Environment Rules

Each flag can define environment-specific rules with:
- ordered condition matching
- reusable segment references
- sticky percentage rollouts
- scheduled activation windows
- direct served values or served variants

### Variants and Payloads

Flags can define named variants with:
- variant key
- variant value
- optional JSON payload

This lets Flagify act as both a feature-flag service and a light remote-config service.

### Prerequisites

Flags can depend on other flags by expected value or variant.

### Runtime Snapshot

Flagify can export an environment snapshot for local/offline evaluation consumers:

```text
GET /api/v1/runtime/projects/{projectId}/environments/{environmentKey}/snapshot
```

### Evaluation Events

Every runtime resolution writes evaluation events with:
- project
- environment
- flag
- client
- served value
- variant
- evaluation reason
- matched rule
- evaluation context

## API Overview

Main resource groups:
- `Projects`
- `Environments`
- `Segments`
- `Flags`
- `Clients`
- `Overrides`
- `Events`
- `Keys`
- `Runtime`

Important runtime endpoints:
- `GET /api/v1/runtime/config`
- `GET /api/v1/runtime/config/{flagKey}`
- `GET /api/v1/runtime/projects/{projectId}/clients/{clientKey}/config`
- `GET /api/v1/runtime/projects/{projectId}/clients/{clientKey}/config/{flagKey}`
- `GET /api/v1/runtime/projects/{projectId}/environments/{environmentKey}/clients/{clientKey}/config`
- `GET /api/v1/runtime/projects/{projectId}/environments/{environmentKey}/clients/{clientKey}/config/{flagKey}`
- `GET /api/v1/runtime/projects/{projectId}/environments/{environmentKey}/snapshot`

Important admin endpoints:
- `POST /api/v1/projects/{projectId}/environments`
- `POST /api/v1/projects/{projectId}/segments`
- `PUT /api/v1/projects/{projectId}/flags/{flagId}/environments/{environmentKey}`
- `GET /api/v1/projects/{projectId}/evaluation-events`

## Example Workflow

1. Create a project.
2. Create a project admin key.
3. Create a client with metadata such as plan, country, or app version.
4. Create a segment, for example `premium_users`.
5. Create a flag with variants and optional prerequisites.
6. Add environment rules for `staging` or `production`.
7. Create a client runtime key.
8. Resolve runtime config or fetch a snapshot.

## API Docs

The API reference lives in [openapi.yaml](/Users/rsoley/.t3/worktrees/Flagify/t3code-aee05d04/openapi.yaml).

The Postman artifacts are:
- [docs/postman/Flagify.postman_collection.json](/Users/rsoley/.t3/worktrees/Flagify/t3code-aee05d04/docs/postman/Flagify.postman_collection.json)
- [docs/postman/Flagify.local.postman_environment.json](/Users/rsoley/.t3/worktrees/Flagify/t3code-aee05d04/docs/postman/Flagify.local.postman_environment.json)

## cPanel Deployment

Git deployment is defined in [/.cpanel.yml](/Users/rsoley/Dev/oss/Flagify/.cpanel.yml).

- Target path: `/home/rsrdev/flagify.rsrdev.com/`
- Requests rewrite through [/.htaccess](/Users/rsoley/Dev/oss/Flagify/.htaccess)

## Notes

- Root administrative access uses `FLAGIFY_BOOTSTRAP_KEY`.
- License: [MIT](/Users/rsoley/.t3/worktrees/Flagify/t3code-aee05d04/LICENSE)
- This is a side project maintained primarily through agent-assisted iteration.
