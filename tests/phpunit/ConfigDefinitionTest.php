<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore\Tests;

use PHPUnit\Framework\TestCase;
use Keboola\App\ProjectRestore\ConfigDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigDefinitionTest extends TestCase
{
    /**
     * @dataProvider provideValidConfigs
     */
    public function testValidConfigDefinition(array $inputConfig, array $expectedConfig): void
    {
        $definition = new ConfigDefinition();
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration($definition, [$inputConfig]);
        $this->assertSame($expectedConfig, $processedConfig);
    }

    /**
     * @return mixed[][]
     */
    public function provideValidConfigs(): array
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
                        'dryRun' => false,
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
                        'dryRun' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidConfigs
     */
    public function testInvalidConfigDefinition(
        array $inputConfig,
        string $expectedExceptionClass,
        string $expectedExceptionMessage
    ): void {
        $definition = new ConfigDefinition();
        $processor = new Processor();
        $this->expectException($expectedExceptionClass);
        $this->expectExceptionMessage($expectedExceptionMessage);
        $processor->processConfiguration($definition, [$inputConfig]);
    }

    /**
     * @return mixed[][]
     */
    public function provideInvalidConfigs(): array
    {
        return [
            'empty parameters' => [
                [
                    'parameters' => [],
                ],
                InvalidConfigurationException::class,
                'ABS or S3 must be configured.',
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
                'The child node "accessKeyId" at path "root.parameters.s3" must be configured.',
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
                'The child node "#secretAccessKey" at path "root.parameters.s3" must be configured.',
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
                'The child node "#sessionToken" at path "root.parameters.s3" must be configured.',
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
                'Invalid type for path "root.parameters.useDefaultBackend". Expected boolean, but got string.',
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
                'The child node "container" at path "root.parameters.abs" must be configured.',
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
                'The child node "#connectionString" at path "root.parameters.abs" must be configured.',
            ],
        ];
    }
}
