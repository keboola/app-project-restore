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
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Component extends BaseComponent
{
    public function run(): void
    {
        $config = $this->getConfig();
        $params = $config->getParameters();

        try {
            $s3UriParts = (new S3UriParser())->parse($params['backupUri']);

            if (empty($s3UriParts['region'])) {
                throw new \InvalidArgumentException(sprintf('Missing region info in uri: %s', $params['backupUri']));
            }
        } catch (\InvalidArgumentException $e) {
            throw new UserException(sprintf('Parameter "backupUri" is not valid: %s', $e->getMessage()));
        }

        $storageApi = $this->initSapi();
        $this->validateProject($storageApi);

        $s3Client = $this->initS3($s3UriParts['region']);

        $logger = $this->initLogger();
        $logger->info('Secret');
        $logger->info($params['#secretAccessKey']);
        $logger->info('Token');
        $logger->info($params['#sessionToken']);
        $restore = new S3Restore($s3Client, $storageApi, $logger);

        try {
            $restore->restoreBuckets($s3UriParts['bucket'], $s3UriParts['key'], true);
            $restore->restoreConfigs($s3UriParts['bucket'], $s3UriParts['key']);
            $restore->restoreTables($s3UriParts['bucket'], $s3UriParts['key']);
            $restore->restoreTableAliases($s3UriParts['bucket'], $s3UriParts['key']);
        } catch (S3Exception $e) {
            throw new UserException($e->getMessage(), 0, $e);
        } catch (StorageApiException $e) {
            throw new UserException($e->getMessage(), 0, $e);
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

    private function initLogger(): Logger
    {
        $formatter = new LineFormatter("%message%\n");

        $errorHandler = new StreamHandler('php://stderr', Logger::WARNING, false);
        $errorHandler->setFormatter($formatter);

        $handler = new StreamHandler('php://stdout', Logger::INFO);
        $handler->setFormatter($formatter);

        $logger = new Logger(
            getenv('KBC_COMPONENTID')?: 'project-restore',
            [
                $errorHandler,
                $handler,
            ]
        );

        return $logger;
    }

    private function initSapi(): StorageApi
    {
        return new StorageApi([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
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
        if (count($components->listComponents())) {
            throw new UserException("Project is not empty. Delete all existing component configurations.");
        }
    }
}
