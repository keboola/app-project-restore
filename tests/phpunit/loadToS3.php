<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\S3\Transfer;

date_default_timezone_set('Europe/Prague');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$basedir = dirname(__FILE__);

require_once $basedir . '/bootstrap.php';

echo "Loading fixtures to S3\n";
$s3Client = new S3Client([
    'version' => 'latest',
    'region' => getenv('TEST_AWS_REGION'),
    'credentials' => [
        'key' => getenv('TEST_AWS_ACCESS_KEY_ID'),
        'secret' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
    ],
]);


// Clean S3 bucket
echo "Removing existing objects from S3\n";
$result = $s3Client->listObjects(['Bucket' => getenv('TEST_AWS_S3_BUCKET')])->toArray();
if (isset($result['Contents'])) {
    if (count($result['Contents']) > 0) {
        $result = $s3Client->deleteObjects(
            [
                'Bucket' => getenv('TEST_AWS_S3_BUCKET'),
                'Delete' => ['Objects' => $result['Contents']],
            ],
        );
    }
}

// Check if all files aws deleted - prevent no delete permission
$result = $s3Client->listObjects(['Bucket' => getenv('TEST_AWS_S3_BUCKET')])->toArray();
if (isset($result['Contents'])) {
    if (count($result['Contents']) > 0) {
        throw new Exception('AWS S3 bucket is not empty');
    }
}

// Where the files will be source from
$source = $basedir . '/data';

// Where the files will be transferred to
$dest = 's3://' . getenv('TEST_AWS_S3_BUCKET') . '/';

// Create a transfer object.
echo "Updating fixtures in S3\n";
$manager = new Transfer($s3Client, $source, $dest, []);

// Perform the transfer synchronously.
$manager->transfer();
