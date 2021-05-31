<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Tests;

use DateTime;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalAbsTest extends TestCase
{
    protected Temp $temp;

    protected StorageApi $sapiClient;

    protected BlobRestProxy $absClient;

    private string $testRunId;

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

        $this->absClient = BlobRestProxy::createBlobService(sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
            (string) getenv('TEST_AZURE_ACCOUNT_NAME'),
            (string) getenv('TEST_AZURE_ACCOUNT_KEY')
        ));

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
        $this->assertStringContainsString('Restoring keboola.csv-import configurations', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreTables(): void
    {
        $this->createConfigFile('tables');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Restoring bucket ', $output);
        $this->assertStringContainsString('Restoring table ', $output);
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
        $this->createConfigFile('configurations-skip');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertNotRegExp('/Restoring bucket /', $output);
        $this->assertNotRegExp('/Restoring table /', $output);
        $this->assertStringContainsString('Restoring keboola.csv-import configurations', $output);

        $this->assertStringContainsString('Skipping orchestrator configurations', $errorOutput);
        $this->assertStringContainsString('Skipping gooddata-writer configurations', $errorOutput);
        $this->assertStringContainsString('Skipping keboola.wr-db-snowflake configurations', $errorOutput);
    }

    public function testRestoreConfigsAppNotify(): void
    {
        $this->createConfigFile('configurations-skip');

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertNotRegExp('/Restoring bucket /', $output);
        $this->assertNotRegExp('/Restoring table /', $output);
        $this->assertStringContainsString('Restoring keboola.csv-import configurations', $output);

        $this->assertStringContainsString('You can transfer orchestrations with Orchestrator', $errorOutput);
        $this->assertStringContainsString('You can transfer writers with GoodData', $errorOutput);
        $this->assertStringContainsString('You can transfer writers with Snowflake', $errorOutput);
    }

    public function testIgnoreSelfConfig(): void
    {
        $this->createConfigFile('configurations');

        $components = new Components($this->sapiClient);

        $configuration = new Configuration();
        $configuration->setComponentId(getenv('TEST_COMPONENT_ID'))
            ->setConfigurationId('self')
            ->setName('Self configuration')
            ->setConfiguration(
                json_decode(
                    (string) file_get_contents($this->temp->getTmpFolder() . '/config.json')
                )
            );

        $components->addConfiguration($configuration);

        $runProcess = $this->createTestProcess('self');
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Restoring keboola.csv-import configurations', $output);

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
        $this->assertStringContainsString('Storage is not empty', $errorOutput);
        $this->assertStringContainsString($bucketId, $errorOutput);

        $this->assertEmpty($output);
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
        $this->assertStringContainsString('Delete all existing component configurations', $errorOutput);

        $this->assertEmpty($output);
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
                $this->sapiClient->dropBucket($bucket['id'], ['force' => true]);
            }
        }

        foreach ($this->sapiClient->listBuckets() as $bucket) {
            $this->sapiClient->dropBucket($bucket['id'], ['force' => true]);
        }
    }

    private function generateFederationTokenForParams(string $blobPrefix): string
    {
        $sasHelper = new BlobSharedAccessSignatureHelper(
            (string) getenv('TEST_AZURE_ACCOUNT_NAME'),
            (string) getenv('TEST_AZURE_ACCOUNT_KEY')
        );

        $expirationDate = (new DateTime())->modify('+1hour');

        $sasToken = $sasHelper->generateBlobServiceSharedAccessSignatureToken(
            Resources::RESOURCE_TYPE_CONTAINER,
            (string) getenv('TEST_AZURE_CONTAINER_NAME') . '-' . $blobPrefix,
            'rl',
            $expirationDate,
            new DateTime('now')
        );

        return sprintf(
            '%s=https://%s.blob.core.windows.net;SharedAccessSignature=%s',
            Resources::BLOB_ENDPOINT_NAME,
            getenv('TEST_AZURE_ACCOUNT_NAME'),
            $sasToken
        );
    }

    private function createTestProcess(?string $configId = null): Process
    {
        $runCommand = 'php /code/src/run.php';
        return Process::fromShellCommandline(
            $runCommand,
            null,
            [
                'KBC_DATADIR' => $this->temp->getTmpFolder(),
                'KBC_URL' => getenv('TEST_STORAGE_API_URL'),
                'KBC_TOKEN' => getenv('TEST_STORAGE_API_TOKEN'),
                'KBC_COMPONENTID' => getenv('TEST_COMPONENT_ID'),
                'KBC_CONFIGID' => $configId,
                'KBC_RUNID' => $this->testRunId,
            ],
            null,
            120.0
        );
    }

    private function createConfigFile(string $blobPrefix): void
    {
        $configFile = new SplFileInfo($this->temp->getTmpFolder() . '/config.json');

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $configFile->getPathname(),
            (string) json_encode([
                'parameters' => [
                    'abs' => [
                        'container' => getenv('TEST_AZURE_CONTAINER_NAME') . '-' . $blobPrefix,
                        '#connectionString' => $this->generateFederationTokenForParams($blobPrefix),
                    ],
                ],

            ])
        );
    }
}
