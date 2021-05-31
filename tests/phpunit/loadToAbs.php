<?php

declare(strict_types=1);

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\Container;
use Symfony\Component\Finder\Finder;

date_default_timezone_set('Europe/Prague');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$basedir = dirname(__FILE__);

require_once $basedir . '/bootstrap.php';

echo 'Loading fixtures to ABS' . PHP_EOL;

$absClient = BlobRestProxy::createBlobService(sprintf(
    'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
    (string) getenv('TEST_AZURE_ACCOUNT_NAME'),
    (string) getenv('TEST_AZURE_ACCOUNT_KEY')
));

echo 'Cleanup files in ABS' . PHP_EOL;
$containers = $absClient->listContainers();
$listContainers = array_map(fn(Container $v) => $v->getName(), $containers->getContainers());

echo 'Copying new files into ABS' . PHP_EOL;
$finder = new Finder();
$dirs = $finder->depth(0)->in($basedir . '/data')->directories();

foreach ($dirs as $dir) {
    $container = getenv('TEST_AZURE_CONTAINER_NAME') . '-' . $dir->getRelativePathname();
    if (!in_array($container, $listContainers)) {
        $absClient->createContainer($container);
    }

    $blobs = $absClient->listBlobs($container);
    foreach ($blobs->getBlobs() as $blob) {
        $absClient->deleteBlob($container, $blob->getName());
    }

    $finder = new Finder();
    $files = $finder->in($dir->getPathname())->files();
    foreach ($files as $file) {
        $fopen = fopen($file->getPathname(), 'r');
        if (!$fopen) {
            continue;
        }
        $absClient->createBlockBlob(
            $container,
            $file->getRelativePathname(),
            $fopen
        );
    }
}

echo 'Fixtures load complete' . PHP_EOL;
