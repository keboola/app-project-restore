<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Storages;

use Keboola\App\ProjectRestore\Config;
use Keboola\ProjectRestore\AbsRestore;
use Keboola\ProjectRestore\Restore;
use Keboola\StorageApi\Client;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Middlewares\RetryMiddlewareFactory;
use Psr\Log\LoggerInterface;

class AzureBlobStorage implements IStorage
{
    private LoggerInterface $logger;

    private Config $config;

    public const SAS_DEFAULT_EXPIRATION_HOURS = 36;

    private const MAX_RETRIES = 20;

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function getRestore(Client $sapi): Restore
    {
        $absClient = BlobRestProxy::createBlobService($this->config->getAbsConnectionString());
        $absClient->pushMiddleware(
            RetryMiddlewareFactory::create(
                RetryMiddlewareFactory::GENERAL_RETRY_TYPE,
                self::MAX_RETRIES,
                3000,
                RetryMiddlewareFactory::EXPONENTIAL_INTERVAL_ACCUMULATION,
                true
            )
        );

        return new AbsRestore(
            $sapi,
            $absClient,
            $this->config->getAbsContainer(),
            $this->logger
        );
    }
}
