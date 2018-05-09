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
    private $temp;

    /**
     * @var S3Client
     */
    private $s3Client;

    /**
     * @var StorageApi
     */
    private $sapiClient;

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
            'Policy' => json_encode($policy)
        ]);

        return [
            'accessKeyId' => $federationToken['Credentials']['AccessKeyId'],
            '#secretAccessKey' => $federationToken['Credentials']['SecretAccessKey'],
            '#sessionToken' => $federationToken['Credentials']['SessionToken'],
        ];
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
                        )
                    ],
                    $this->generateFederationTokenForParams()
                ),
            ])
        );

        $runCommand = "KBC_DATADIR={$this->temp->getTmpFolder()} php /code/src/run.php";
        $runProcess = new  Process($runCommand);
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $this->assertContains('Restoring bucket c-bucket', $output);
        $this->assertContains('Restoring keboola.csv-import configurations', $output);
        $this->assertContains('Restoring table in.c-bucket.Account', $output);
    }

    public function testNotEmptyProjectErrorRun(): void
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
                        )
                    ],
                    $this->generateFederationTokenForParams()
                ),
            ])
        );

        $runCommand = "KBC_DATADIR={$this->temp->getTmpFolder()} php /code/src/run.php";

        // existing bucket
        $bucketId = $this->sapiClient->createBucket('old', StorageApi::STAGE_IN);

        $runProcess = new Process($runCommand);
        $runProcess->run();

        $errorOutput = $runProcess->getOutput();
        $this->assertEquals(1, $runProcess->getExitCode());
        $this->assertContains('Storage is not empty', $errorOutput);
        $this->assertContains($bucketId, $errorOutput);

        $this->sapiClient->dropBucket($bucketId, ["force" => true]);

        // existing configurations
        $components = new Components($this->sapiClient);

        $configuration = new Configuration();
        $configuration->setComponentId('keboola.csv-import')
            ->setConfigurationId('old')
            ->setName('Old configuration');

        $components->addConfiguration($configuration);

        $runProcess = new Process($runCommand);
        $runProcess->run();

        $this->assertEquals(1, $runProcess->getExitCode());
        $this->assertContains('Delete all existing component configurations', $runProcess->getOutput());
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
                            'https://%s.s3.amazonaws.com',
                            getenv('TEST_AWS_S3_BUCKET')
                        )
                    ],
                    $this->generateFederationTokenForParams()
                ),
            ])
        );

        $runCommand = "KBC_DATADIR={$this->temp->getTmpFolder()} php /code/src/run.php";
        $runProcess = new Process($runCommand);
        $runProcess->run();

        $this->assertEquals(1, $runProcess->getExitCode());
        $this->assertContains(' Missing region info', $runProcess->getOutput());
    }

    private function cleanupKbcProject()
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
}