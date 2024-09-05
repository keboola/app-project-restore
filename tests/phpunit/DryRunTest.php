<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Tests;

use DateTime;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\Temp\Temp;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DryRunTest extends TestCase
{
    protected Temp $temp;

    protected StorageApi $sapiClient;

    protected BlobRestProxy $absClient;

    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->temp = new Temp('project-restore');

        $this->sapiClient = new StorageApi([
            'url' => getenv('TEST_STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);

        $this->cleanupKbcProject();

        $this->absClient = BlobRestProxy::createBlobService(sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
            getenv('TEST_AZURE_ACCOUNT_NAME'),
            getenv('TEST_AZURE_ACCOUNT_KEY'),
        ));

        $this->testRunId = $this->sapiClient->generateRunId();
    }

    public function testDryRunMode(): void
    {
        $configFile = new SplFileInfo($this->temp->getTmpFolder() . '/config.json');

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $configFile->getPathname(),
            (string) json_encode([
                'parameters' => [
                    'abs' => [
                        'container' => getenv('TEST_AZURE_CONTAINER_NAME') . '-configurations',
                        '#connectionString' => $this->generateFederationTokenForParams('configurations'),
                    ],
                    'restoreConfigs' => true,
                    'dryRun' => true,
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('[dry-run] Restore project metadata', $output);
        $this->assertMatchesRegularExpression(
            '/\[dry-run\] Restore configuration [0-9]+ \(component "keboola.csv-import"\)/',
            $output,
        );
        $this->assertMatchesRegularExpression(
            '/\[dry-run\] Restore state of configuration [0-9]+ \(component "keboola.csv-import"\)/',
            $output,
        );

        $this->assertEmpty($errorOutput);
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
                $this->sapiClient->dropBucket(
                    $bucket['id'],
                    [
                        'force' => true,
                        'async' => true,
                    ],
                );
            }
        }

        foreach ($this->sapiClient->listBuckets() as $bucket) {
            $this->sapiClient->dropBucket(
                $bucket['id'],
                [
                    'force' => true,
                    'async' => true,
                ],
            );
        }
    }

    private function generateFederationTokenForParams(string $blobPrefix): string
    {
        $sasHelper = new BlobSharedAccessSignatureHelper(
            (string) getenv('TEST_AZURE_ACCOUNT_NAME'),
            (string) getenv('TEST_AZURE_ACCOUNT_KEY'),
        );

        $expirationDate = (new DateTime())->modify('+1hour');

        $sasToken = $sasHelper->generateBlobServiceSharedAccessSignatureToken(
            Resources::RESOURCE_TYPE_CONTAINER,
            getenv('TEST_AZURE_CONTAINER_NAME') . '-' . $blobPrefix,
            'rl',
            $expirationDate,
            new DateTime('now'),
        );

        return sprintf(
            '%s=https://%s.blob.core.windows.net;SharedAccessSignature=%s',
            Resources::BLOB_ENDPOINT_NAME,
            getenv('TEST_AZURE_ACCOUNT_NAME'),
            $sasToken,
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
            120.0,
        );
    }
}
