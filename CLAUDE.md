# app-project-restore – AI Development Context

## What this repository does

Keboola App component for restoring a project from a backup in cloud storage (S3, ABS, GCS). Wraps the `php-kbc-project-restore` library. Run by `app-project-migrate` as Phase 3 of the pipeline, but can also be run standalone.

## Documentation

- **`docs/overview.md`** – restore sequence, skipped components, parallel table creation
- **`docs/how-it-works.md`** – step-by-step restore flow (checkEmptyProject, 3 phases of restoreTables, why aliases come after tables)
- **`docs/configuration.md`** – complete input parameter reference for all 3 backends

## Required environment variables

Before running tests, verify that these variables are present in `.env` (or exported in the shell). If missing, ask for them explicitly.

**For AWS S3 tests:**

| Variable | Description |
|---|---|
| `TEST_STORAGE_API_URL` | Keboola project URL (destination) |
| `TEST_STORAGE_API_TOKEN` | Project storage token (destination) |
| `TEST_AWS_ACCESS_KEY_ID` | AWS Access Key ID |
| `TEST_AWS_SECRET_ACCESS_KEY` | AWS Secret Access Key |
| `TEST_AWS_REGION` | AWS region (e.g. `us-east-1`) |
| `TEST_AWS_S3_BUCKET` | S3 bucket name containing the backup |

**For Azure ABS tests:**

| Variable | Description |
|---|---|
| `TEST_AZURE_ACCOUNT_NAME` | Azure storage account name |
| `TEST_AZURE_ACCOUNT_KEY` | Azure storage key |
| `TEST_AZURE_CONTAINER_NAME` | Azure Blob Storage container name containing the backup |

**For GCS tests:**

| Variable | Description |
|---|---|
| `TEST_GCP_SERVICE_ACCOUNT` | JSON service account key (full JSON as string) |
| `TEST_GCP_BUCKET` | GCS bucket name containing the backup |

**For functional tests:**

| Variable | Description |
|---|---|
| `TEST_COMPONENT_ID` | Component ID used in datadir tests |

**Platform-injected (automatically set by Keboola Runner when running in Keboola):**

| Variable | Description |
|---|---|
| `KBC_RUNID` | Job run ID, set on the SAPI client |
| `KBC_COMPONENTID` | Current component ID – used in `validateProject()` to allow self-configuration in an otherwise empty project |
| `KBC_CONFIGID` | Current configuration ID – see above |

> Check that the `.env` file exists in the repo root. If not, create it based on the list above.

## Development commands

Service name in `docker-compose.yml` is `dev`. The `composer tests` script runs `tests-prepare-abs` + `tests-prepare-s3` + `tests-prepare-gcs` + `tests-phpunit` in sequence.

```bash
docker compose run --rm dev composer phpcs
docker compose run --rm dev composer phpstan
docker compose run --rm dev composer tests           # prepare + phpunit
docker compose run --rm dev composer tests-phpunit   # phpunit only without data preparation
docker compose run --rm dev composer tests-prepare-s3    # S3 data preparation only
docker compose run --rm dev composer tests-prepare-abs   # ABS data preparation only
docker compose run --rm dev composer tests-prepare-gcs   # GCS data preparation only
```

## Key files

| File | Purpose |
|---|---|
| `src/Component.php` | Entry point |
| `src/Application.php` | Restore orchestration |
| `src/Config.php` | Configuration getters |
| `src/ConfigDefinition.php` | Parameter validation |
| `src/S3UriParser.php` | S3 URI parser (`s3://bucket/path` → bucket + key) |
| `src/Storages/` | Adapters for S3, ABS, GCS |

## Important constants in Application.php

**COMPONENTS_WITH_CUSTOM_RESTORE** – components skipped during `restoreConfigs()`:
```
orchestrator, gooddata-writer,
keboola.wr-db-snowflake, keboola.wr-snowflake-blob-storage,
keboola.wr-db-snowflake-gcs, keboola.wr-db-snowflake-gcs-s3
```

**IGNORED_CHECK_COMPONENTS** – ignored during `checkEmptyProject()`:
```
keboola.app-project-migrate-large-tables, keboola.app-project-migrate, keboola.orchestrator
```

## Orchestrator

Orchestrator configurations are restored but with `isDisabled: true`. Users must manually re-enable after verifying the migration.

## Parallel table creation

Tables are created via child processes (`worker-create-table.php`). Number of processes: `tableParallelism` (default 10).

## Coding standards

- PHP 8.x with strict types
- PHPStan level max
- Keboola coding standard (PSR-12)

## Related repositories

- Library: `php-kbc-project-restore`
- Orchestrator: `app-project-migrate`
- Paired with: `app-project-backup`
