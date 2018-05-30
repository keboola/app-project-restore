# Project Migration - Restore from AWS S3 Snapshot

[![Build Status](https://travis-ci.org/keboola/app-project-restore.svg?branch=master)](https://travis-ci.org/keboola/app-project-restore)

> You can use [Project Migrate](https://github.com/keboola/app-project-migrate) application which orchestrates whole process of KBC project migration from one KBC stack to another.

Application for restore KBC project from snapshot generated by `keboola.project-backup` app (https://github.com/keboola/app-project-backup).

**Destination project must be empty!** (Contains any buckets in Storage and component configurations.)

### Application flow

1. Validates if destination project is empty
2. Creates empty buckets and restore their attributes and metadata
    - Skip linked and system buckets
    - Do not restore bucket sharing settings
    - Use storage backend due `useDefaultBackend` settings
3. Creates component configurations
    - Including configuration rows
    - Including configuration and row state
    - Remove OAuth authorizations from configuration
    - Orchestrations, GoodData Writers and Snowflake database writers **are not restored** automatically
4. Creates empty tables and restore their attributes and metadata
5. Imports data into tables
6. Creates table aliases

# Usage

Use parameters generated by [`generate-read-credentials`](https://github.com/keboola/app-project-backup#1-prepare-storage-for-backup) action of `keboola.project-backup` app:

- `backupUri`
- `accessKeyId`
- `#secretAccessKey`
- `#sessionToken`

Optional params
- `useDefaultBackend` _(boolean, default false)_ - Use default storage backend, otherwise buckets will be created in same backend as in source project.

Example request for restore project in Keboola Connection EU:
```
curl -X POST \
  https://docker-runner.eu-central-1.keboola.com/docker/keboola.project-restore/run \
  -H 'Cache-Control: no-cache' \
  -H 'X-StorageApi-Token: **EU_STORAGE_API_TOKEN**' \
  -d '{
	"configData": {
		"parameters": {
			"backupUri": "**BACKUP_URI**",
			"accessKeyId": "**AWS_ACCESS_KEY**",
			"#secretAccessKey": "**AWS_SECRET_ACCESS_KEY**",
			"#sessionToken": "**AWS_SESSION_TOKEN",
			"useDefaultBackend": true
		}
	}
}'
```

### Component specific migrations

You can use prepared applications to migrate Orchestrations, GoodData Writers and Snowflake database writers between projects:

- Project Migration - Orchestrator: `keboola.app-orchestrator-migrate` app (https://github.com/keboola/app-orchestrator-migrate)
- Project Migration - GoodData Writer: `keboola.app-gooddata-writer-migrate` app (https://github.com/keboola/app-gooddata-writer-migrate)
- Project Migration - Snowflake Writer: `keboola.app-snowflake-writer-migrate` app (https://github.com/keboola/app-snowflake-writer-migrate)

## Development
 
- Clone this repository:

```
git clone https://github.com/keboola/app-project-restore.git
cd app-project-restore
```


- Create AWS services from CloudFormation template [aws-tests-cf-template.json](./aws-tests-cf-template.json)

    It will create new S3 bucket and IAM User in AWS

- Create `.env` file an fill variables:

    - `TEST_AWS_*` - Output of your CloudFormation stack
    - `TEST_STORAGE_API_URL` - KBC Storage API endpoint
    - `TEST_STORAGE_API_TOKEN` - KBC Storage API token
    - `TEST_COMPONENT_ID` - Restore APP component ID in KBC _(keboola.project-restore)_
    
```
TEST_AWS_ACCESS_KEY_ID=
TEST_AWS_SECRET_ACCESS_KEY=
TEST_AWS_REGION=
TEST_AWS_S3_BUCKET=

TEST_COMPONENT_ID=keboola.project-restore

TEST_STORAGE_API_URL=
TEST_STORAGE_API_TOKEN=
```

- Build Docker image

```
docker-compose build
```

- Run the test suite using this command

    **Tests will delete all current component configurations and data from the KBC project!**

```
docker-compose run --rm dev composer ci
```
 
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 
