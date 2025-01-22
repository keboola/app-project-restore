<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Storages;

use Google\Auth\FetchAuthTokenInterface;
use Google\Cloud\Storage\StorageClient;
use Keboola\App\ProjectRestore\Config;
use Keboola\ProjectRestore\GcsRestore;
use Keboola\ProjectRestore\Restore;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

class GoogleStorage implements IStorage
{
    private StorageClient $storageClient;

    public function __construct(readonly Config $config, readonly LoggerInterface $logger)
    {
        $this->storageClient = $this->initGcs(
            $this->config->getGcsCredentials(),
            $this->config->getGcsProjectId(),
        );
    }

    public function getRestore(Client $sapi): Restore
    {
        $retBucket = $this->storageClient->bucket($this->config->getGcsBucket());
        $objectPath = $this->config->getGcsBackupUri();
        if (!str_ends_with($this->config->getGcsBackupUri(), '/')) {
            $objectPath .= '/';
        }
        $object = $retBucket->object($objectPath . 'signedUrls.json');

        return new GcsRestore(
            $sapi,
            (array) json_decode($object->downloadAsString(), true),
            $this->logger,
        );
    }

    private function initGcs(array $credentials, string $projectId): StorageClient
    {
        $fetchAuthToken = $this->getAuthTokenClass([
            'access_token' => $credentials['accessToken'],
            'expires_in' => $credentials['expiresIn'],
            'token_type' => $credentials['tokenType'],
        ]);
        return new StorageClient([
            'projectId' => $projectId,
            'credentialsFetcher' => $fetchAuthToken,
        ]);
    }

    private function getAuthTokenClass(array $credentials): FetchAuthTokenInterface
    {
        return new class ($credentials) implements FetchAuthTokenInterface {
            private array $creds;

            public function __construct(
                array $creds,
            ) {
                $this->creds = $creds;
            }

            public function fetchAuthToken(?callable $httpHandler = null): array
            {
                return $this->creds;
            }

            public function getCacheKey(): string
            {
                return '';
            }

            public function getLastReceivedToken(): array
            {
                return $this->creds;
            }
        };
    }
}
