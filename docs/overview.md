# app-project-restore – overview

## What the component does

Restores a Keboola project from a backup in cloud storage (S3, ABS or GCS). This is a Keboola App component that wraps the `php-kbc-project-restore` library.

Run by `app-project-migrate` as Phase 3 of the migration pipeline, but can also be run standalone – e.g. for restoring a project from a periodic backup.

## Restore sequence

Steps always run in this order and each can be individually disabled via configuration:

1. `restoreProjectMetadata()` – default branch metadata
2. `restoreBuckets()` – creates storage buckets
3. `restoreConfigs(skipComponents)` – all configurations except those with special handling
4. `restoreTables(parallelism)` – creates table structures (parallel worker processes) – requires both `restoreBuckets: true` AND `restoreTables: true`
5. `restoreTableAliases()` – alias tables (must come after tables) – runs together with step 4
6. `restoreTriggers()`
7. `restoreNotifications()`
8. `restorePermanentFiles()`

## Skipped components (COMPONENTS_WITH_CUSTOM_RESTORE)

These components are **not restored** by this repository. They require dedicated migration applications:

| Component ID | Who handles it |
|---|---|
| `orchestrator` | Orchestrator Migrate App (outside this system) |
| `gooddata-writer` | GoodData Writer Migrate App (outside this system) |
| `keboola.wr-db-snowflake` | `app-snowflake-writer-migrate` |
| `keboola.wr-snowflake-blob-storage` | `app-snowflake-writer-migrate` |
| `keboola.wr-db-snowflake-gcs` | `app-snowflake-writer-migrate` |
| `keboola.wr-db-snowflake-gcs-s3` | `app-snowflake-writer-migrate` |

## Orchestrator – special behavior

`keboola.orchestrator` configurations are restored (structure is preserved), but automatically set to `isDisabled: true`. Users must manually re-enable orchestrations after verifying the migration.

## Parallel table creation

Tables are created using `symfony/process` worker scripts (`worker-create-table.php`). The number of parallel processes is determined by `tableParallelism` (default: 10). Work items are interleaved across buckets so parallel workers don't operate on the same bucket simultaneously.

## checkEmptyProject

Before restoring, the component verifies that the destination project is empty. The following are ignored during the check:

```php
const IGNORED_CHECK_COMPONENTS = [
    'keboola.app-project-migrate-large-tables',
    'keboola.app-project-migrate',
    'keboola.orchestrator',
];
```

## Architecture

```
Component.php
  └─ Application.php
       └─ php-kbc-project-restore (library)
            ├─ S3Restore / AbsRestore / GcsRestore
            └─ worker-create-table.php (child process, one per table)
```

## Key files

| File | Description |
|---|---|
| `src/Component.php` | Entry point |
| `src/Application.php` | Restore orchestration |
| `src/Config.php` | Configuration getters |
| `src/ConfigDefinition.php` | Parameter validation |
| `src/Storages/` | Adapters for S3, ABS, GCS |

## Development and testing

```bash
docker compose run --rm dev composer phpcs
docker compose run --rm dev composer phpstan
docker compose run --rm dev composer tests
```

## Related repositories

- Library: `php-kbc-project-restore`
- Uses it: `app-project-migrate` (Phase 3)
- Paired with: `app-project-backup`
