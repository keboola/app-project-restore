# app-project-restore – how the restore works

## Call flow (Application::run)

```
Component.php
  └─ Application::run()
       1. initSapi()
       2. (if checkEmptyProject) validateProject()
       3. storageBackend->getRestore()  → S3Restore / AbsRestore / GcsRestore
       4. (if dryRun) restore->setDryRunMode()
       5. restore->setForcePrimaryKeyNotNull()
       6. Restore sequence (each step can be disabled):
            restoreProjectMetadata()
            restoreBuckets(!useDefaultBackend)
            restoreConfigs(COMPONENTS_WITH_CUSTOM_RESTORE)
            restoreTables(tableParallelism)
            restoreTableAliases()
            restoreTriggers()
            restoreNotifications()
            restorePermanentFiles()
       7. Warnings for skipped components (orchestrator, gooddata, snowflake writers)
```

## Step 1: checkEmptyProject

Before restoring, verifies that the destination project is empty:

```
listBuckets()  →  if buckets exist → UserException

listComponents() filtered:
  - keboola.app-project-migrate-large-tables  (ignored)
  - keboola.app-project-migrate               (ignored)
  - keboola.orchestrator                      (ignored)
  - current component's own configuration     (ignored)

if other configurations remain → UserException
```

Ignored components are part of the migration process and may be present in the destination project without meaning that the project is non-empty.

## Step 2: restoreProjectMetadata

Restores default branch metadata from `defaultBranchMetadata.json`.

```
getDataFromStorage('defaultBranchMetadata.json')
DevBranchesMetadata::addBranchMetadata(metadata)
```

## Step 3: restoreBuckets

```
getDataFromStorage('buckets.json') → list of buckets

if checkBackend:
  – verifies the project has the required backend (MySQL/Redshift/Snowflake)

forEach bucket:
  – skips sys buckets (name does not start with 'c-')
  – skips linked buckets
  – createBucket(name, stage, description, backend)
  – postBucketMetadata(metadata)
```

`useDefaultBackend: true` → `backend` from backup is ignored, the project's default backend is used.

## Step 4: restoreConfigs

`Application.php` only calls `$restore->restoreConfigs(COMPONENTS_WITH_CUSTOM_RESTORE)`. The actual logic (reading `configurations.json` from storage, creating configurations in SAPI) is implemented in `php-kbc-project-restore/Restore.php`.

Skips components in `COMPONENTS_WITH_CUSTOM_RESTORE`:
- `orchestrator`, `gooddata-writer`
- `keboola.wr-db-snowflake`, `keboola.wr-snowflake-blob-storage`
- `keboola.wr-db-snowflake-gcs`, `keboola.wr-db-snowflake-gcs-s3`

For each other configuration:
```
addConfiguration()     – empty configuration with ID
  if orchestrator:     isDisabled = true  ← important!
updateConfiguration()  – fills data, ConfigurationCorrector translates component IDs
updateConfigurationState()
forEach row:
  addConfigurationRow() + updateConfigurationRow() + updateConfigurationRowState()
(if rowsSortOrder) updateConfiguration() – sets row order
(if .json.metadata) addConfigurationMetadata()
```

### Why orchestrator is disabled

`keboola.orchestrator` configurations are restored with `isDisabled: true`. Otherwise, orchestrations could automatically start in the destination project before all data and configurations are properly set up. Users must manually re-enable orchestrations after verifying the migration.

### ConfigurationCorrector

When restoring to a different stack, the `componentId` in a configuration may differ (e.g. `keboola.wr-db-snowflake` vs. `keboola.wr-db-snowflake-gcs`). `ConfigurationCorrector` translates IDs via `StackSpecificComponentIdTranslator`.

## Step 5: restoreTables – 3 phases

### Phase 1: Prepare work items

```
getDataFromStorage('tables.json')
forEach table:
  – skips alias tables
  – skips tables whose bucket does not exist
  – builds workerInput: sapiUrl, sapiToken, bucketId, tableName, columns, primaryKey, isTyped, ...
```

### Phase 2: Parallel table creation (worker processes)

```
interleaveByBucket(workItems)
  → interleaving: tables from different buckets alternate
  → reason: Snowflake does not handle parallel operations well on the same bucket

createTablesParallel(workItems, parallelism=10):
  while pendingItems or runningProcesses:
    – starts up to `parallelism` processes (worker-create-table.php)
    – each worker receives workerInput via STDIN as JSON
    – on completion reads JSON output from STDOUT
    – results: originalTableId → createdTableId mapping
```

### Why interleaveByBucket

When creating tables in parallel, Snowflake locks metadata at the bucket (schema) level. If multiple workers operated on the same bucket simultaneously, conflicts would occur. Interleaving ensures each worker always operates on a different bucket.

### Phase 3: Sequential data and metadata upload

```
forEach createdTable:
  restoreTableColumnsMetadata()  – column-level metadata
  listTableFiles(tableId)        – finds files in backup storage

  if 1 file without .part_0.csv.gz (non-sliced):
    uploadFile() + writeTableAsyncDirect()

  if sliced:
    downloadSlices → uploadSlicedFile(federationToken=true) → writeTableAsyncDirect()
```

## Step 6: restoreTableAliases

Must run **after** `restoreTables`, because an alias references an already existing table.

```
forEach alias table (isAlias === true):
  – verifies the source table exists
  – verifies the destination bucket exists
  – createAliasTable(bucketId, sourceTableId, name, aliasOptions)
  – restoreTableColumnsMetadata()
```

## Dry-run mode

If `dryRun: true`:
- No SAPI operations are performed (no `create`, `update`, `upload`)
- Each skipped operation is logged as `[dry-run] ...`
- Storage is read normally (to determine what would be restored)

## forcePrimaryKeyNotNull

If a worker returns `isNullablePkError: true`, the table has a nullable PK column. This normally causes an error. With `forcePrimaryKeyNotNull: true`, the column is changed to NOT NULL before setting the PK.
