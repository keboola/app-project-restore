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

    /**
     * @var string
     */
    private $testRunId;

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

        $this->testRunId = $this->sapiClient->generateRunId();
    }

    public function testRestoreConfigs(): void
    {
        $this->createConfigFile('configurations');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertNotRegExp('/Restoring bucket /', $output);
        $this->assertNotRegExp('/Restoring table /', $output);
        $this->assertContains('Restoring keboola.csv-import configurations', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreTables(): void
    {
        $this->createConfigFile('tables');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertContains('Restoring bucket ', $output);
        $this->assertContains('Restoring table ', $output);
        $this->assertNotRegExp('/Restoring [^\s]+ configurations/', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRunIdPropagation(): void
    {
        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertCount(0, $events);

        $this->createConfigFile('tables');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertGreaterThan(0, count($events));

        $this->assertCount(1, array_filter(
            $events,
            function (array $event) {
                return $event['event'] === 'storage.tableCreated';
            }
        ));
    }

    public function testRestoreObsoleteConfigs(): void
    {
        $this->createConfigFile('configurations-obsolete');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertNotRegExp('/Restoring bucket /', $output);
        $this->assertNotRegExp('/Restoring table /', $output);
        $this->assertContains('Restoring keboola.csv-import configurations', $output);

        $this->assertContains('Skipping orchestrator configurations', $errorOutput);
        $this->assertContains('Skipping gooddata-writer configurations', $errorOutput);
    }

    public function testRestoreConfigsAppNotify(): void
    {
        $this->createConfigFile('configurations-obsolete');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertNotRegExp('/Restoring bucket /', $output);
        $this->assertNotRegExp('/Restoring table /', $output);
        $this->assertContains('Restoring keboola.csv-import configurations', $output);

        $this->assertContains('You can transfer orchestrations with Orchestrator', $errorOutput);
        $this->assertContains('You can transfer writers with GoodData', $errorOutput);
    }

    public function testSuccessfulRun(): void
    {
        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertCount(0, $events);

        $this->createConfigFile('base');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $this->assertContains('Restoring bucket c-bucket', $output);
        $this->assertContains('Restoring keboola.csv-import configurations', $output);
        $this->assertContains('Restoring table in.c-bucket.Account', $output);

        $errorOutput = $runProcess->getErrorOutput();
        $this->assertContains('Skipping orchestrator configurations', $errorOutput);
        $this->assertContains('Skipping gooddata-writer configurations', $errorOutput);
        $this->assertContains('You can transfer orchestrations with Orchestrator', $errorOutput);
        $this->assertContains('You can transfer writers with GoodData', $errorOutput);

        $this->assertCount(4, explode(PHP_EOL, trim($errorOutput)));

        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertGreaterThan(0, count($events));
    }

    public function testIgnoreSelfValidationRun(): void
    {
        $this->createConfigFile('base');
        $components = new Components($this->sapiClient);

        $configuration = new Configuration();
        $configuration->setComponentId(getenv('TEST_COMPONENT_ID'))
            ->setConfigurationId('self')
            ->setName('Self configuration')
            ->setConfiguration(json_decode(file_get_contents($this->temp->getTmpFolder() . '/config.json')));

        $components->addConfiguration($configuration);

        $runProcess = $this->createTestProcess('self');
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $this->assertNotContains('Project is not empty. Delete all existing component configurations.', $output);
        $this->assertContains('Restoring bucket c-bucket', $output);
        $this->assertContains('Restoring keboola.csv-import configurations', $output);
        $this->assertContains('Restoring table in.c-bucket.Account', $output);

        $errorOutput = $runProcess->getErrorOutput();
        $this->assertContains('Skipping orchestrator configurations', $errorOutput);
        $this->assertContains('Skipping gooddata-writer configurations', $errorOutput);
        $this->assertContains('You can transfer orchestrations with Orchestrator', $errorOutput);
        $this->assertContains('You can transfer writers with GoodData', $errorOutput);

        $this->assertCount(4, explode(PHP_EOL, trim($errorOutput)));
    }

    public function testIgnoreSelfConfig(): void
    {
        $this->createConfigFile('configurations');

        $components = new Components($this->sapiClient);

        $configuration = new Configuration();
        $configuration->setComponentId(getenv('TEST_COMPONENT_ID'))
            ->setConfigurationId('self')
            ->setName('Self configuration')
            ->setConfiguration(json_decode(file_get_contents($this->temp->getTmpFolder() . '/config.json')));

        $components->addConfiguration($configuration);

        $runProcess = $this->createTestProcess('self');
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertContains('Restoring keboola.csv-import configurations', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testExistingBucketsUserError(): void
    {
        $this->createConfigFile('tables');

        $bucketId = $this->sapiClient->createBucket('old', StorageApi::STAGE_IN);

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEquals(1, $runProcess->getExitCode());
        $this->assertContains('Storage is not empty', $output);
        $this->assertContains($bucketId, $output);

        $this->assertEmpty($errorOutput);
    }

    public function testExistingConfigsUserError(): void
    {
        $this->createConfigFile('tables');

        $components = new Components($this->sapiClient);

        $configuration = new Configuration();
        $configuration->setComponentId('keboola.csv-import')
            ->setConfigurationId('old')
            ->setName('Old configuration');

        $components->addConfiguration($configuration);

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEquals(1, $runProcess->getExitCode());
        $this->assertContains('Delete all existing component configurations', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testNotEmptyProjectErrorRun(): void
    {
        $this->createConfigFile('base');

        // existing bucket
        $bucketId = $this->sapiClient->createBucket('old', StorageApi::STAGE_IN);

        $runProcess = $this->createTestProcess();
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

        $runProcess = $this->createTestProcess();
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

    private function createTestProcess(?string $configId = null): Process
    {
        $runCommand = "php /code/src/run.php";
        return new  Process($runCommand, null, [
            'KBC_DATADIR' => $this->temp->getTmpFolder(),
            'KBC_URL' => getenv('TEST_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_STORAGE_API_TOKEN'),
            'KBC_COMPONENTID' => getenv('TEST_COMPONENT_ID'),
            'KBC_CONFIGID' => $configId,
            'KBC_RUNID' => $this->testRunId,
        ]);
    }

    private function createConfigFile(string $testCase): \SplFileInfo
    {
        $configFile = new \SplFileInfo($this->temp->getTmpFolder() . '/config.json');

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $configFile->getPathname(),
            \json_encode([
                'parameters' => array_merge(
                    [
                        'backupUri' => sprintf(
                            'https://%s.s3.%s.amazonaws.com/%s/',
                            getenv('TEST_AWS_S3_BUCKET'),
                            getenv('TEST_AWS_REGION'),
                            $testCase
                        ),
                    ],
                    $this->generateFederationTokenForParams()
                ),
            ])
        );

        return $configFile;
    }
}
