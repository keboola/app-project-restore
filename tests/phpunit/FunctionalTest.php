<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Tests;

use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalTest extends TestCase
{
    /**
     * @var Temp
     */
    protected $temp;

    /**
     * @var StorageApi
     */
    protected $sapiClient;

    /**
     * @var S3Client
     */
    protected $s3Client;

    public function setUp(): void
    {
        parent::setUp();

        $this->temp = new Temp('project-restore');
        $this->temp->initRunFolder();

        $this->sapiClient = new StorageApi([
            'url' => getenv('TEST_STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);

        $this->cleanupKbcProject();

        putenv('AWS_ACCESS_KEY_ID=' . getenv('TEST_AWS_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('TEST_AWS_SECRET_ACCESS_KEY'));

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => getenv('TEST_AWS_REGION'),
        ]);
    }

    public function testSuccessfulRun(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'parameters' => array_merge(
                    [
                        'backupUri' => sprintf(
                            'https://%s.s3.%s.amazonaws.com',
                            getenv('TEST_AWS_S3_BUCKET'),
                            getenv('TEST_AWS_REGION')
                        ),
                    ],
                    $this->generateFederationTokenForParams()
                ),
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $this->assertContains('Restoring bucket c-bucket', $output);
        $this->assertContains('Restoring keboola.csv-import configurations', $output);
        $this->assertContains('Restoring table in.c-bucket.Account', $output);
    }

    public function testMissingRegionUriRun(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'parameters' => array_merge(
                    [
                        'backupUri' => sprintf(
                            'https://%s.i-dont-know.com',
                            getenv('TEST_AWS_S3_BUCKET')
                        ),
                    ],
                    $this->generateFederationTokenForParams()
                ),
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(1, $runProcess->getExitCode());
        $this->assertContains(' Missing region info', $runProcess->getOutput());
    }

    private function cleanupKbcProject(): void
    {
        $components = new Components($this->sapiClient);
        foreach ($components->listComponents() as $component) {
            foreach ($component['configurations'] as $configuration) {
                $components->deleteConfiguration($component['id'], $configuration['id']);

                // delete configuration from trash
                $components->deleteConfiguration($component['id'], $configuration['id']);
            }
        }

        // drop linked buckets
        foreach ($this->sapiClient->listBuckets() as $bucket) {
            if (isset($bucket['sourceBucket'])) {
                $this->sapiClient->dropBucket($bucket["id"], ["force" => true]);
            }
        }

        foreach ($this->sapiClient->listBuckets() as $bucket) {
            $this->sapiClient->dropBucket($bucket["id"], ["force" => true]);
        }
    }

    private function generateFederationTokenForParams(): array
    {
        $sts =  new StsClient([
            'version' => 'latest',
            'region' => getenv('TEST_AWS_REGION'),
        ]);

        $policy = [
            'Statement' => [
                [
                    'Effect' =>'Allow',
                    'Action' => 's3:GetObject',
                    'Resource' => ['arn:aws:s3:::' . getenv('TEST_AWS_S3_BUCKET') . '/*'],
                ],
                [
                    'Effect' => 'Allow',
                    'Action' => 's3:ListBucket',
                    'Resource' => ['arn:aws:s3:::' . getenv('TEST_AWS_S3_BUCKET')],
                ],
            ],
        ];

        $federationToken = $sts->getFederationToken([
            'DurationSeconds' => 3600,
            'Name' => 'GetProjectRestoreFile',
            'Policy' => json_encode($policy),
        ]);

        return [
            'accessKeyId' => $federationToken['Credentials']['AccessKeyId'],
            '#secretAccessKey' => $federationToken['Credentials']['SecretAccessKey'],
            '#sessionToken' => $federationToken['Credentials']['SessionToken'],
        ];
    }

    private function createTestProcess(): Process
    {
        $runCommand = "php /code/src/run.php";
        return new  Process($runCommand, null, [
            'KBC_DATADIR' => $this->temp->getTmpFolder(),
            'KBC_URL' => getenv('TEST_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
    }
}
