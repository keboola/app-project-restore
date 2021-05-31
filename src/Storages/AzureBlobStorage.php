<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Storages;

use Keboola\App\ProjectRestore\Config;
use Keboola\ProjectRestore\AbsRestore;
use Keboola\ProjectRestore\Restore;
use Keboola\StorageApi\Client;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Psr\Log\LoggerInterface;

class AzureBlobStorage implements IStorage
{
    private LoggerInterface $logger;

    private Config $config;

    public const SAS_DEFAULT_EXPIRATION_HOURS = 36;

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function getRestore(Client $sapi): Restore
    {
        return new AbsRestore(
            $sapi,
            BlobRestProxy::createBlobService($this->config->getAbsConnectionString()),
            $this->config->getAbsContainer(),
            $this->logger
        );
    }
}
