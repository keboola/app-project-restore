# Configuration parameter reference – app-project-restore

## Restore switches

Default value is `true` unless stated otherwise.

| Parameter | Default | Description |
|---|---|---|
| `restoreProjectMetadata` | `true` | Restores default branch metadata |
| `restoreConfigs` | `true` | Restores component configurations |
| `restoreBuckets` | `true` | Creates storage buckets |
| `restoreTables` | `true` | Creates table structures. **Note:** condition is `restoreBuckets && restoreTables` – if `restoreBuckets: false`, tables will not be restored even if `restoreTables: true` |
| `restorePermanentFiles` | `true` | Restores permanent files |
| `restoreTriggers` | `true` | Restores flow triggers |
| `restoreNotifications` | `true` | Restores notification subscriptions |

## Restore options

| Parameter | Default | Description |
|---|---|---|
| `dryRun` | `false` | Simulation without actual changes |
| `forcePrimaryKeyNotNull` | `false` | Forces NOT NULL on PK columns in typed tables (resolves issues when migrating between backends) |
| `tableParallelism` | `10` | Number of parallel worker processes for table creation |
| `useDefaultBackend` | `false` | Ignores storage backend from backup, uses destination project's default backend |
| `checkEmptyProject` | `true` | Refuses restore if destination project is not empty |

## S3 credentials

Used if the backup is on S3.

| Parameter | Required | Description |
|---|---|---|
| `s3.backupUri` | ✓ | Full S3 URI of the backup (e.g. `s3://bucket/path/to/backup`) |
| `s3.accessKeyId` | ✓ | AWS Access Key ID |
| `s3.#secretAccessKey` | ✓ | AWS Secret Access Key |
| `s3.#sessionToken` | ✓ | Session token (typically from `generate-read-credentials` – temporary STS credentials) |

## ABS credentials (Azure Blob Storage)

Used if the backup is on ABS.

| Parameter | Required | Description |
|---|---|---|
| `abs.container` | ✓ | Azure Blob Storage container name |
| `abs.#connectionString` | ✓ | Azure connection string |

## GCS credentials (Google Cloud Storage)

Used if the backup is on GCS.

| Parameter | Required | Description |
|---|---|---|
| `gcs.backupUri` | ✓ | Full GCS URI of the backup (e.g. `gs://bucket/path/to/backup`) |
| `gcs.bucket` | ✓ | GCS bucket name |
| `gcs.projectId` | ✓ | Google Cloud project ID |
| `gcs.credentials.#accessToken` | ✓ | Access token (temporary, from `generate-read-credentials`) |
| `gcs.credentials.expiresIn` | ✓ | Token expiry time |
| `gcs.credentials.tokenType` | ✓ | Token type (e.g. `Bearer`) |

> Exactly one backend (s3, abs, or gcs) must always be configured.

## Configuration examples

### Full restore from S3

S3 credentials are typically temporary STS credentials from `generate-read-credentials` (all 3 parameters including `#sessionToken` are required):

```json
{
  "parameters": {
    "s3": {
      "backupUri": "s3://my-kbc-backups/12345",
      "accessKeyId": "ASIA...",
      "#secretAccessKey": "secret",
      "#sessionToken": "FwoGZXIvYXdzE..."
    }
  }
}
```

### Restore from S3 (configurations only, no tables or files)

```json
{
  "parameters": {
    "s3": {
      "backupUri": "s3://my-kbc-backups/12345",
      "accessKeyId": "ASIA...",
      "#secretAccessKey": "secret",
      "#sessionToken": "FwoGZXIvYXdzE..."
    },
    "restoreTables": false,
    "restorePermanentFiles": false
  }
}
```

### Restore from GCS with temporary credentials

```json
{
  "parameters": {
    "gcs": {
      "backupUri": "gs://my-kbc-backups/12345",
      "bucket": "my-kbc-backups",
      "projectId": "my-gcp-project",
      "credentials": {
        "#accessToken": "ya29...",
        "expiresIn": "3600",
        "tokenType": "Bearer"
      }
    },
    "tableParallelism": 20
  }
}
```

### Dry run (simulation without changes)

```json
{
  "parameters": {
    "s3": {
      "backupUri": "s3://my-kbc-backups/12345",
      "accessKeyId": "AKIA...",
      "#secretAccessKey": "secret",
      "#sessionToken": "FwoGZXIvYXdzE..."
    },
    "dryRun": true
  }
}
```
