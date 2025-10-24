<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore;

use Keboola\App\ProjectRestore\Storages\AwsS3Storage;
use Keboola\App\ProjectRestore\Storages\AzureBlobStorage;
use Keboola\App\ProjectRestore\Storages\GoogleStorage;
use Keboola\App\ProjectRestore\Storages\IStorage;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Exception as StorageApiException;
use Psr\Log\LoggerInterface;

class Application
{
    private Config $config;

    private LoggerInterface $logger;

    private IStorage $storageBackend;

    public const COMPONENTS_WITH_CUSTOM_RESTORE = [
        'orchestrator',
        'gooddata-writer',
        'keboola.wr-db-snowflake',
        'keboola.wr-snowflake-blob-storage',
        'keboola.wr-db-snowflake-gcs',
        'keboola.wr-db-snowflake-gcs-s3',
    ];

    private const IGNORED_CHECK_COMPONENTS = [
        'keboola.app-project-migrate-large-tables',
        'keboola.app-project-migrate',
        'keboola.orchestrator',
    ];

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        switch ($this->config->getStorageBackendType()) {
            case Config::STORAGE_BACKEND_S3:
                $this->storageBackend = new AwsS3Storage($config, $logger);
                break;
            case Config::STORAGE_BACKEND_ABS:
                $this->storageBackend = new AzureBlobStorage($config, $logger);
                break;
            case Config::STORAGE_BACKEND_GCS:
                $this->storageBackend = new GoogleStorage($config, $logger);
                break;
        }
    }

    public function run(): void
    {
        $storageApi = $this->initSapi();
        if ($this->config->checkEmptyProject()) {
            $this->validateProject($storageApi);
        }

        $params = $this->config->getParameters();

        $restore = $this->storageBackend->getRestore($storageApi);

        if ($this->config->isDryRun()) {
            $restore->setDryRunMode();
        }

        try {
            if ($this->config->shouldRestoreProjectMetadata()) {
                $restore->restoreProjectMetadata();
            }
            if ($this->config->shouldRestoreBuckets()) {
                $restore->restoreBuckets(!$params['useDefaultBackend']);
            }
            if ($this->config->shouldRestoreConfigs()) {
                $restore->restoreConfigs(
                    self::COMPONENTS_WITH_CUSTOM_RESTORE,
                    $this->config->getConfigurationsToMigrate(),
                );
            }
            if ($this->config->shouldRestoreBuckets() && $this->config->shouldRestoreTables()) {
                $restore->restoreTables($this->config->getTablesToMigrate());
                $restore->restoreTableAliases($this->config->getTablesToMigrate());
            }
            if ($this->config->shouldRestoreTriggers()) {
                $restore->restoreTriggers();
            }
            if ($this->config->shouldRestoreNotifications()) {
                $restore->restoreNotifications();
            }
            if ($this->config->shouldRestorePermanentFiles()) {
                $restore->restorePermanentFiles();
            }
        } catch (StorageApiException $e) {
            throw new UserException($e->getMessage(), 0, $e);
        }

        // notify orchestrations
        if (count($restore->listConfigsInBackup('orchestrator'))) {
            $this->logger->warning(
                'Orchestrations was not restored. You can transfer orchestrations with Orchestrator Migrate App',
            );
        }

        // notify gooddata writers
        if (count($restore->listConfigsInBackup('gooddata-writer'))) {
            $this->logger->warning(
                'GoodData writers was not restored. You can transfer writers with GoodData Writer Migrate App',
            );
        }

        // notify snowflake writers
        if (count($restore->listConfigsInBackup('keboola.wr-db-snowflake'))
            || count($restore->listConfigsInBackup('keboola.wr-snowflake-blob-storage'))
            || count($restore->listConfigsInBackup('keboola.wr-db-snowflake-gcs'))
            || count($restore->listConfigsInBackup('keboola.wr-db-snowflake-gcs-s3'))
        ) {
            $this->logger->warning(
                'Snowflake writers was not restored. You can transfer writers with Snowflake Writer Migrate App',
            );
        }
    }

    private function initSapi(): StorageApi
    {
        $storageApi = new StorageApi([
            'url' => $this->config->getKbcUrl(),
            'token' => $this->config->getKbcToken(),
        ]);

        $storageApi->setRunId(getenv('KBC_RUNID'));
        return $storageApi;
    }

    /**
     * Check if project is empty without buckets and configurations
     * @throws UserException
     */
    private function validateProject(StorageApi $storageApi): void
    {
        $bucketIds = array_map(
            function ($bucket) {
                return $bucket['id'];
            },
            $storageApi->listBuckets(),
        );

        if (count($bucketIds) > 0) {
            throw new UserException(sprintf('Storage is not empty. Existing buckets: %s', join(', ', $bucketIds)));
        }

        $components = new Components($storageApi);
        $componentsConfigurations = $components->listComponents();

        foreach ($componentsConfigurations ?? [] as $k => $component) {
            if (in_array($component['id'], self::IGNORED_CHECK_COMPONENTS)) {
                unset($componentsConfigurations[$k]);
            }
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

        if (count($componentsConfigurations)) {
            throw new UserException('Project is not empty. Delete all existing component configurations.');
        }
    }
}
