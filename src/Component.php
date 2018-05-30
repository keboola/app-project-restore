<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\ProjectRestore\S3Restore;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Exception as StorageApiException;

class Component extends BaseComponent
{
    public const COMPONENTS_WITH_CUSTOM_RESTORE = [
      'orchestrator',
      'gooddata-writer',
      'keboola.wr-db-snowflake',
    ];

    public function run(): void
    {
        $config = $this->getConfig();
        $params = $config->getParameters();

        try {
            $s3UriParts = (new S3UriParser())->parse($params['backupUri']);

            if (empty($s3UriParts['region'])) {
                throw new \InvalidArgumentException(sprintf('Missing region info in uri: %s', $params['backupUri']));
            }

            $s3Bucket = $s3UriParts['bucket'];
            $s3Region = $s3UriParts['region'];
            $s3Path = $s3UriParts['key'];
        } catch (\InvalidArgumentException $e) {
            throw new UserException(sprintf('Parameter "backupUri" is not valid: %s', $e->getMessage()));
        }

        $storageApi = $this->initSapi();

        $this->validateProject($storageApi);

        $s3Client = $this->initS3($s3Region);

        $restore = new S3Restore($s3Client, $storageApi, $this->getLogger());

        try {
            $restore->restoreBuckets($s3Bucket, $s3Path, !$params['useDefaultBackend']);
            $restore->restoreConfigs($s3Bucket, $s3Path, self::COMPONENTS_WITH_CUSTOM_RESTORE);
            $restore->restoreTables($s3Bucket, $s3Path);
            $restore->restoreTableAliases($s3Bucket, $s3Path);
        } catch (S3Exception $e) {
            throw new UserException($e->getMessage(), 0, $e);
        } catch (StorageApiException $e) {
            throw new UserException($e->getMessage(), 0, $e);
        }

        // notify orchestrations
        if (count($restore->listConfigsInBackup($s3Bucket, $s3Path, 'orchestrator'))) {
            $this->getLogger()->warning('Orchestrations was not restored. You can transfer orchestrations with Orchestrator Migrate App');
        }

        // notify gooddata writers
        if (count($restore->listConfigsInBackup($s3Bucket, $s3Path, 'gooddata-writer'))) {
            $this->getLogger()->warning('GoodData writers was not restored. You can transfer writers with GoodData Writer Migrate App');
        }

        // notify snowflake writers
        if (count($restore->listConfigsInBackup($s3Bucket, $s3Path, 'keboola.wr-db-snowflake'))) {
            $this->getLogger()->warning('Snowflake writers was not restored. You can transfer writers with Snowflake Writer Migrate App');
        }
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }

    private function initSapi(): StorageApi
    {
        $storageApi = new StorageApi([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);

        $storageApi->setRunId(getenv('KBC_RUNID'));
        return $storageApi;
    }

    private function initS3(string $region): S3Client
    {
        $params = $this->getConfig()->getParameters();

        return new S3Client([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $params['accessKeyId'],
                'secret' => $params['#secretAccessKey'],
                'token' => $params['#sessionToken'],
            ],
        ]);
    }

    /**
     * Check if project is empty without buckets and configurations
     *
     * @param StorageApi $storageApi
     * @throws UserException
     */
    private function validateProject(StorageApi $storageApi): void
    {
        $bucketIds = array_map(
            function ($bucket) {
                return $bucket['id'];
            },
            $storageApi->listBuckets()
        );

        if (count($bucketIds) > 0) {
            throw new UserException(sprintf('Storage is not empty. Existing buckets: %s', join(', ', $bucketIds)));
        }

        $components = new Components($storageApi);
        $componentsConfigurations = $components->listComponents();
        if (!count($componentsConfigurations)) {
            return;
        }

        // ignore self configuration
        if (count($componentsConfigurations) === 1) {
            $component = $componentsConfigurations[0];
            if ($component['id'] === getenv('KBC_COMPONENTID') && count($component['configurations']) === 1) {
                $configuration = $component['configurations'][0];

                if ($configuration['id'] === getenv('KBC_CONFIGID')) {
                    return;
                }
            }
        }

        if (count($components->listComponents())) {
            throw new UserException("Project is not empty. Delete all existing component configurations.");
        }
    }
}
