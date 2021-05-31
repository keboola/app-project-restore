<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Storages;

use Keboola\ProjectRestore\Restore;
use Keboola\StorageApi\Client;

interface IStorage
{
    public function getRestore(Client $sapi): Restore;
}
