<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Tests;

use Keboola\App\ProjectRestore\ConfigDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigDefinitionTest extends TestCase
{
    #[DataProvider('provideValidConfigs')]
    public function testValidConfigDefinition(array $inputConfig, array $expectedConfig): void
    {
        $definition = new ConfigDefinition();
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration($definition, [$inputConfig]);
        $this->assertSame($expectedConfig, $processedConfig);
    }

    /**
     * @return array[]
     */
    public static function provideValidConfigs(): array
    {
        return [
            'config s3' => [
                [
                    'parameters' => [
                        's3' => [
                            'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                            'accessKeyId' => 'test-user',
                            '#secretAccessKey' => 'secret',
                            '#sessionToken' => 'token',
                        ],
                    ],
                ],
                [
                    'parameters' => [
                        's3' => [
                            'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                            'accessKeyId' => 'test-user',
                            '#secretAccessKey' => 'secret',
                            '#sessionToken' => 'token',
                        ],
                        'useDefaultBackend' => false,
                        'restoreConfigs' => true,
                        'restorePermanentFiles' => true,
                        'restoreTriggers' => true,
                        'restoreNotifications' => true,
                        'restoreBuckets' => true,
                        'restoreTables' => true,
                        'restoreProjectMetadata' => true,
                        'dryRun' => false,
                        'checkEmptyProject' => true,
                    ],
                ],
            ],
            'config abs' => [
                [
                    'parameters' => [
                        'abs' => [
                            'container' => 'test-container',
                            '#connectionString' => 'secret',
                        ],
                        'restoreConfigs' => false,
                    ],
                ],
                [
                    'parameters' => [
                        'abs' => [
                            'container' => 'test-container',
                            '#connectionString' => 'secret',
                        ],
                        'restoreConfigs' => false,
                        'useDefaultBackend' => false,
                        'restorePermanentFiles' => true,
                        'restoreTriggers' => true,
                        'restoreNotifications' => true,
                        'restoreBuckets' => true,
                        'restoreTables' => true,
                        'restoreProjectMetadata' => true,
                        'dryRun' => false,
                        'checkEmptyProject' => true,
                    ],
                ],
            ],
            'config gcs' => [
                [
                    'parameters' => [
                        'gcs' => [
                            'backupUri' => 'https://project-restore.storage.googleapis.com/some-path',
                            'bucket' => 'test-bucket',
                            'projectId' => 'test-project',
                            'credentials' => [
                                '#accessToken' => 'test-token',
                                'expiresIn' => '1',
                                'tokenType' => 'Bearer',
                            ],
                        ],
                        'restoreConfigs' => false,
                    ],
                ],
                [
                    'parameters' => [
                        'gcs' => [
                            'backupUri' => 'https://project-restore.storage.googleapis.com/some-path',
                            'bucket' => 'test-bucket',
                            'projectId' => 'test-project',
                            'credentials' => [
                                '#accessToken' => 'test-token',
                                'expiresIn' => '1',
                                'tokenType' => 'Bearer',
                            ],
                        ],
                        'restoreConfigs' => false,
                        'useDefaultBackend' => false,
                        'restorePermanentFiles' => true,
                        'restoreTriggers' => true,
                        'restoreNotifications' => true,
                        'restoreBuckets' => true,
                        'restoreTables' => true,
                        'restoreProjectMetadata' => true,
                        'dryRun' => false,
                        'checkEmptyProject' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param class-string<InvalidConfigurationException> $expectedExceptionClass
     */
    #[DataProvider('provideInvalidConfigs')]
    public function testInvalidConfigDefinition(
        array $inputConfig,
        string $expectedExceptionClass,
        string $expectedExceptionMessage,
    ): void {
        $definition = new ConfigDefinition();
        $processor = new Processor();
        $this->expectException($expectedExceptionClass);
        $this->expectExceptionMessage($expectedExceptionMessage);
        $processor->processConfiguration($definition, [$inputConfig]);
    }

    /**
     * @return array[]
     */
    public static function provideInvalidConfigs(): array
    {
        return [
            'empty parameters' => [
                [
                    'parameters' => [],
                ],
                InvalidConfigurationException::class,
                'Only one of ABS, S3, or GCS needs to be configured.',
            ],
            'extra parameters' => [
                [
                    'parameters' => [
                        'other' => 'something',
                    ],
                ],
                InvalidConfigurationException::class,
                'Unrecognized option "other" under "root.parameters"',
            ],
            'missing accessKeyId' => [
                [
                    'parameters' => [
                        's3' => [
                            'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                        ],
                    ],
                ],
                InvalidConfigurationException::class,
                'The child config "accessKeyId" under "root.parameters.s3" must be configured.',
            ],
            'missing secretAccessKey' => [
                [
                    'parameters' => [
                        's3' => [
                            'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                            'accessKeyId' => 'test-user',
                        ],
                    ],
                ],
                InvalidConfigurationException::class,
                'The child config "#secretAccessKey" under "root.parameters.s3" must be configured.',
            ],
            'missing sessionToken' => [
                [
                    'parameters' => [
                        's3' => [
                            'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                            'accessKeyId' => 'test-user',
                            '#secretAccessKey' => 'secret',
                        ],
                    ],
                ],
                InvalidConfigurationException::class,
                'The child config "#sessionToken" under "root.parameters.s3" must be configured.',
            ],
            'bad value for default backend' => [
                [
                    'parameters' => [
                        's3' => [
                            'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                            'accessKeyId' => 'test-user',
                            '#secretAccessKey' => 'secret',
                        ],
                        'useDefaultBackend' => 'fake',
                    ],
                ],
                InvalidConfigurationException::class,
                'Invalid type for path "root.parameters.useDefaultBackend". Expected "bool", but got "string".',
            ],
            'missing container' => [
                [
                    'parameters' => [
                        'abs' => [
                            '#connectionString' => 'secret',
                        ],
                    ],
                ],
                InvalidConfigurationException::class,
                'The child config "container" under "root.parameters.abs" must be configured.',
            ],
            'missing connectionString' => [
                [
                    'parameters' => [
                        'abs' => [
                            'container' => 'test-container',
                        ],
                    ],
                ],
                InvalidConfigurationException::class,
                'The child config "#connectionString" under "root.parameters.abs" must be configured.',
            ],
            'setup all storages' => [
                [
                    'parameters' => [
                        'abs' => [
                            'container' => 'test-container',
                            '#connectionString' => 'secret',
                        ],
                        's3' => [
                            'backupUri' => 'uri',
                            'accessKeyId' => 'test-user',
                            '#secretAccessKey' => 'secret',
                            '#sessionToken' => 'token',
                        ],
                        'gcs' => [
                            'backupUri' => 'uri',
                            'bucket' => 'test-bucket',
                            'projectId' => 'test-project',
                            'credentials' => [
                                '#accessToken' => 'test-token',
                                'expiresIn' => '1',
                                'tokenType' => 'Bearer',
                            ],
                        ],
                    ],
                ],
                InvalidConfigurationException::class,
                'Only one of ABS, S3, or GCS needs to be configured.',
            ],
        ];
    }
}
