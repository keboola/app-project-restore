<?php

declare(strict_types=1);

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use Keboola\Csv\CsvFile;
use Keboola\ProjectRestore\Tests\GcsRestoreTest;
use Keboola\Temp\Temp;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

date_default_timezone_set('Europe/Prague');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$basedir = dirname(__FILE__);

require_once $basedir . '/bootstrap.php';

echo 'Loading fixtures to GCS' . PHP_EOL;

$storageClient = new StorageClient([
    'keyFile' => json_decode((string) getenv('TEST_GCP_SERVICE_ACCOUNT'), true),
]);

// delete from GCS
echo 'Cleanup files in GCS' . PHP_EOL;
foreach ($storageClient->bucket((string) getenv('TEST_GCP_BUCKET'))->objects() as $object) {
    $object->delete();
}

// generate a lot of table slices
$system = new Filesystem();

$temp = new Temp('loadToGCS');

$source = $temp->getTmpFolder();
$finder = new Finder();
$dirs = $finder->depth(0)->in($basedir . '/data')->directories();

echo 'Copying new files into GCS' . PHP_EOL;
foreach ($dirs as $dir) {
    uploadDirToGcs($storageClient, $dir->getPathname(), $dir->getRelativePathname());
}

echo 'Fixtures load complete' . PHP_EOL;

function buildTreeFromPath(array $signedUrls, StorageObject $object, string $path): array
{
    $parts = explode('/', $path);
    $filename = pathinfo(array_pop($parts), PATHINFO_BASENAME);

    $current = &$signedUrls;
    foreach ($parts as $part) {
        if (!isset($current[$part])) {
            $current[$part] = [];
        }
        $current = &$current[$part];
    }

    $current[$filename] = $object->signedUrl(new DateTimeImmutable('+2 days'));
    return $signedUrls;
}

function uploadDirToGcs(StorageClient $storageClient, string $localDir, string $gcsDir): void
{
    $files = (new Finder())->in($localDir)->files();
    $signedUrls = [];
    foreach ($files as $file) {
        $pathname = $gcsDir . '/' . $file->getRelativePathname();
        /** @var resource $openedFile */
        $openedFile = fopen($file->getRealPath(), 'r');
        $object = $storageClient->bucket((string) getenv('TEST_GCP_BUCKET'))->upload(
            $openedFile,
            [
                'name' => $pathname,
            ],
        );

        $signedUrls = buildTreeFromPath($signedUrls, $object, $file->getRelativePathname());
    }
    $storageClient->bucket((string) getenv('TEST_GCP_BUCKET'))->upload(
        (string) json_encode($signedUrls, JSON_PRETTY_PRINT),
        [
            'name' => $gcsDir . '/' . 'signedUrls.json',
        ],
    );
}
