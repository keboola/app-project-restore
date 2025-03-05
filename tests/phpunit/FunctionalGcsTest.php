<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Tests;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Cloud\Storage\StorageClient;
use Google\Service\CloudSecurityToken;
use Google\Service\CloudSecurityToken\GoogleIdentityStsV1ExchangeTokenRequest;
use Google_Client;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalGcsTest extends TestCase
{
    protected Temp $temp;

    protected StorageApi $sapiClient;

    protected StorageClient $storageClient;

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

        $this->storageClient = new StorageClient([
            'keyFile' => json_decode((string) getenv('TEST_GCP_SERVICE_ACCOUNT'), true),
        ]);

        $this->testRunId = $this->sapiClient->generateRunId();
    }

    public function testRestoreConfigs(): void
    {
        $this->createConfigFile('configurations', true);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertDoesNotMatchRegularExpression('/Restoring bucket /', $output);
        $this->assertDoesNotMatchRegularExpression('/Restoring table /', $output);
        $this->assertStringContainsString('Restoring keboola.csv-import configurations', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreConfigsDisabled(): void
    {
        $this->createConfigFile('configurations', false);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Downloading buckets', $output);
        $this->assertStringContainsString('Downloading tables', $output);
        $this->assertStringContainsString('Downloading configurations', $output);
        $this->assertStringNotContainsString('Restoring keboola.csv-import configurations', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestorePermanentFiles(): void
    {
        $this->createConfigFile('configurations', false, true);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Downloading buckets', $output);
        $this->assertStringContainsString('Downloading tables', $output);
        $this->assertStringContainsString('Downloading configurations', $output);
        $this->assertStringContainsString('Downloading permanent files', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestorePermanentFilesDisabled(): void
    {
        $this->createConfigFile('configurations', false, false);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Downloading buckets', $output);
        $this->assertStringContainsString('Downloading tables', $output);
        $this->assertStringContainsString('Downloading configurations', $output);
        $this->assertStringContainsString('Downloading triggers', $output);
        $this->assertStringContainsString('Downloading notifications', $output);
        $this->assertStringNotContainsString('Downloading permanent files', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreTriggersDisabled(): void
    {
        $this->createConfigFile('configurations', false, true, false);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Downloading buckets', $output);
        $this->assertStringContainsString('Downloading tables', $output);
        $this->assertStringContainsString('Downloading configurations', $output);
        $this->assertStringContainsString('Downloading permanent files', $output);
        $this->assertStringContainsString('Downloading notifications', $output);
        $this->assertStringNotContainsString('Downloading triggers', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreBucketsDisabled(): void
    {
        $this->createConfigFile(
            'configurations',
            true,
            true,
            true,
            true,
            false,
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringNotContainsString('Downloading buckets', $output);
        $this->assertStringNotContainsString('Downloading tables', $output);
        $this->assertStringContainsString('Downloading configurations', $output);
        $this->assertStringContainsString('Downloading permanent files', $output);
        $this->assertStringContainsString('Downloading notifications', $output);
        $this->assertStringContainsString('Downloading triggers', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreTablesDisabled(): void
    {
        $this->createConfigFile(
            'configurations',
            true,
            true,
            true,
            true,
            true,
            false,
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Downloading buckets', $output);
        $this->assertStringNotContainsString('Downloading tables', $output);
        $this->assertStringContainsString('Downloading configurations', $output);
        $this->assertStringContainsString('Downloading permanent files', $output);
        $this->assertStringContainsString('Downloading notifications', $output);
        $this->assertStringContainsString('Downloading triggers', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreNotificationsDisabled(): void
    {
        $this->createConfigFile('configurations', false, true, true, false);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Downloading buckets', $output);
        $this->assertStringContainsString('Downloading tables', $output);
        $this->assertStringContainsString('Downloading configurations', $output);
        $this->assertStringContainsString('Downloading permanent files', $output);
        $this->assertStringContainsString('Downloading triggers', $output);
        $this->assertStringNotContainsString('Downloading notifications', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRestoreTables(): void
    {
        $this->createConfigFile('tables', true);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertStringContainsString('Restoring bucket ', $output);
        $this->assertStringContainsString('Restoring table ', $output);
        $this->assertDoesNotMatchRegularExpression('/Restoring [^\s]+ configurations/', $output);

        $this->assertEmpty($errorOutput);
    }

    public function testRunIdPropagation(): void
    {
        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertCount(0, $events);

        $this->createConfigFile('tables', true);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertGreaterThan(0, count($events));

        $this->assertCount(1, array_filter(
            $events,
            function (array $event) {
                return $event['event'] === 'storage.tableCreated';
            },
        ));
    }

    public function testRestoreObsoleteConfigs(): void
    {
        $this->createConfigFile('configurations-skip', true);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertDoesNotMatchRegularExpression('/Restoring bucket /', $output);
        $this->assertDoesNotMatchRegularExpression('/Restoring table /', $output);
        $this->assertStringContainsString('Restoring keboola.csv-import configurations', $output);

        $this->assertStringContainsString('Skipping orchestrator configurations', $errorOutput);
        $this->assertStringContainsString('Skipping gooddata-writer configurations', $errorOutput);
        $this->assertStringContainsString('Skipping keboola.wr-db-snowflake configurations', $errorOutput);
    }

    public function testRestoreConfigsAppNotify(): void
    {
        $this->createConfigFile('configurations-skip', true);

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertDoesNotMatchRegularExpression('/Restoring bucket /', $output);
        $this->assertDoesNotMatchRegularExpression('/Restoring table /', $output);
        $this->assertStringContainsString('Restoring keboola.csv-import configurations', $output);

        $this->assertStringContainsString('You can transfer orchestrations with Orchestrator', $errorOutput);
        $this->assertStringContainsString('You can transfer writers with GoodData', $errorOutput);
        $this->assertStringContainsString('You can transfer writers with Snowflake', $errorOutput);
    }

    public function testIgnoreSelfConfig(): void
    {
        $this->createConfigFile('configurations', true);

        $components = new Components($this->sapiClient);

        $configuration = new Configuration();
        $configuration->setComponentId(getenv('TEST_COMPONENT_ID'))
            ->setConfigurationId('self')
            ->setName('Self configuration')
            ->setConfiguration(
                json_decode(
                    (string) file_get_contents($this->temp->getTmpFolder() . '/config.json'),
                ),
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
        $this->createConfigFile('tables', true);

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
        $this->createConfigFile('tables', true);

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

    private function generateReadCredentials(string $path): array
    {
        /** @var array{
         *     project_id: string,
         * } $jsonKey
         */
        $jsonKey = json_decode((string) getenv('TEST_GCP_SERVICE_ACCOUNT'), true);

        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $jsonKey,
        );

        $httpHandler = HttpHandlerFactory::build();
        $authToken = $credentials->fetchAuthToken($httpHandler);

        $sts = new CloudSecurityToken(new Google_Client([
            'keyFile' => $jsonKey,
        ]));
        $request = new GoogleIdentityStsV1ExchangeTokenRequest();

        $permissionOptions = [
            'accessBoundary' => [
                'accessBoundaryRules' => [
                    [
                        'availableResource' => sprintf(
                            '//storage.googleapis.com/projects/_/buckets/%s',
                            (string) getenv('TEST_GCP_BUCKET'),
                        ),
                        'availablePermissions' => [
                            'inRole:roles/storage.objectViewer',
                        ],
                        'availabilityCondition' => [
                            'expression' => sprintf(
                                'resource.name == \'projects/_/buckets/%s/objects/%s\'',
                                (string) getenv('TEST_GCP_BUCKET'),
                                $path . '/signedUrls.json',
                            ),
                        ],
                    ],
                ],
            ],
        ];

        $request->setOptions(urlencode(json_encode($permissionOptions, JSON_THROW_ON_ERROR)));
        $request->setGrantType('urn:ietf:params:oauth:grant-type:token-exchange');
        $request->setRequestedTokenType('urn:ietf:params:oauth:token-type:access_token');
        $request->setSubjectToken($authToken['access_token']);
        $request->setSubjectTokenType('urn:ietf:params:oauth:token-type:access_token');

        $response = $sts->v1->token($request);

        return [
            '#accessToken' => $response['accessToken'],
            'expiresIn' => $response['expiresIn'],
            'tokenType' => $response['tokenType'],
        ];
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

    private function createConfigFile(
        string $directory,
        bool $restoreConfigs,
        bool $restorePermanentFiles = true,
        bool $restoreTriggers = true,
        bool $restoreNotifications = true,
        bool $restoreBuckets = true,
        bool $restoreTables = true,
    ): void {
        $configFile = new SplFileInfo($this->temp->getTmpFolder() . '/config.json');
        $jsonKey = (array) json_decode((string) getenv('TEST_GCP_SERVICE_ACCOUNT'), true);

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $configFile->getPathname(),
            (string) json_encode([
                'parameters' => [
                    'gcs' => [
                        'backupUri' => $directory,
                        'bucket' => (string) getenv('TEST_GCP_BUCKET'),
                        'projectId' => $jsonKey['project_id'],
                        'credentials' => $this->generateReadCredentials($directory),
                    ],
                    'restoreConfigs' => $restoreConfigs,
                    'restorePermanentFiles' => $restorePermanentFiles,
                    'restoreTriggers' => $restoreTriggers,
                    'restoreNotifications' => $restoreNotifications,
                    'restoreBuckets' => $restoreBuckets,
                    'restoreTables' => $restoreTables,
                ],
            ]),
        );
    }
}
