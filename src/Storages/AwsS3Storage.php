<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Storages;

use Aws\S3\S3Client;
use InvalidArgumentException;
use Keboola\App\ProjectRestore\Config;
use Keboola\App\ProjectRestore\S3UriParser;
use Keboola\Component\UserException;
use Keboola\ProjectRestore\Restore;
use Keboola\ProjectRestore\S3Restore;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

class AwsS3Storage implements IStorage
{
    private Config $config;

    private LoggerInterface $logger;

    private string $bucket;

    private string $region;

    private string $path;

    public const FEDERATION_TOKEN_EXPIRATION_HOURS = 36;

    private const MAX_RETRIES = 5;

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        try {
            $s3UriParts = (new S3UriParser())->parse($config->getAwsBackupUri());

            if (empty($s3UriParts['region'])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Missing region info in uri: %s',
                        $config->getAwsBackupUri(),
                    ),
                );
            }

            $this->bucket = $s3UriParts['bucket'];
            $this->region = $s3UriParts['region'];
            $this->path = $s3UriParts['key'];
        } catch (InvalidArgumentException $e) {
            throw new UserException(sprintf('Parameter "backupUri" is not valid: %s', $e->getMessage()));
        }
    }

    public function getRestore(Client $sapi): Restore
    {
        return new S3Restore(
            $sapi,
            $this->initS3(),
            $this->bucket,
            $this->path,
            $this->logger,
        );
    }

    private function initS3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'retries' => self::MAX_RETRIES,
            'credentials' => [
                'key' => $this->config->getAwsAccessKeyId(),
                'secret' => $this->config->getAwsSecretAccessKey(),
                'token' => $this->config->getAwsSessionToken(),
            ],
        ]);
    }
}
