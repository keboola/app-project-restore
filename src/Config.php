<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore;

use Keboola\Component\Config\BaseConfig;
use Keboola\Component\UserException;

class Config extends BaseConfig
{
    public const STORAGE_BACKEND_S3 = 's3';

    public const STORAGE_BACKEND_ABS = 'abs';

    public function getStorageBackendType(): string
    {
        if ($this->getValue(['parameters', 'abs'], false)) {
            return self::STORAGE_BACKEND_ABS;
        }

        if ($this->getValue(['parameters', 's3'], false)) {
            return self::STORAGE_BACKEND_S3;
        }

        throw new UserException('Unknown storage backend type.');
    }

    public function getAwsBackupUri(): string
    {
        return $this->getValue(['parameters', 's3', 'backupUri']);
    }

    public function getAwsAccessKeyId(): string
    {
        return $this->getValue(['parameters', 's3', 'accessKeyId']);
    }

    public function getAwsSecretAccessKey(): string
    {
        return $this->getValue(['parameters', 's3', '#secretAccessKey']);
    }

    public function getAwsSessionToken(): string
    {
        return $this->getValue(['parameters', 's3', '#sessionToken']);
    }

    public function getAbsConnectionString(): string
    {
        return $this->getValue(['parameters', 'abs', '#connectionString']);
    }

    public function getAbsContainer(): string
    {
        return $this->getValue(['parameters', 'abs', 'container']);
    }

    public function getKbcUrl(): string
    {
        return (string) getenv('KBC_URL');
    }

    public function getKbcToken(): string
    {
        return (string) getenv('KBC_TOKEN');
    }

    public function shouldRestoreConfigs(): bool
    {
        return $this->getValue(['parameters', 'restoreConfigs']);
    }

    public function shouldRestorePermanentFiles(): bool
    {
        return $this->getValue(['parameters', 'restorePermanentFiles']);
    }

    public function isDryRun(): bool
    {
        return $this->getValue(['parameters', 'dryRun']);
    }
}
