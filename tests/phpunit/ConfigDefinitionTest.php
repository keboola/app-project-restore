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
            'config' => [
                [
                    'parameters' => [
                        'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                        'accessKeyId' => 'test-user',
                        '#secretAccessKey' => 'secret',
                        '#sessionToken' => 'token',
                    ],
                ],
                [
                    'parameters' => [
                        'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                        'accessKeyId' => 'test-user',
                        '#secretAccessKey' => 'secret',
                        '#sessionToken' => 'token',
                    ],
                ],
            ],
            'config with extra params' => [
                [
                    'parameters' => [
                        'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                        'accessKeyId' => 'test-user',
                        '#secretAccessKey' => 'secret',
                        '#sessionToken' => 'token',
                        'other' => 'something',
                    ],
                ],
                [
                    'parameters' => [
                        'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                        'accessKeyId' => 'test-user',
                        '#secretAccessKey' => 'secret',
                        '#sessionToken' => 'token',
                        'other' => 'something',
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
                'The child node "backupUri" at path "root.parameters" must be configured.',
            ],
            'missing accessKeyId' => [
                [
                    'parameters' => [
                        'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                    ],
                ],
                InvalidConfigurationException::class,
                'The child node "accessKeyId" at path "root.parameters" must be configured.',
            ],
            'missing secretAccessKey' => [
                [
                    'parameters' => [
                        'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                        'accessKeyId' => 'test-user',
                    ],
                ],
                InvalidConfigurationException::class,
                'The child node "#secretAccessKey" at path "root.parameters" must be configured.',
            ],
            'missing sessionToken' => [
                [
                    'parameters' => [
                        'backupUri' => 'https://project-restore.s3.eu-central-1.amazonaws.com/some-path',
                        'accessKeyId' => 'test-user',
                        '#secretAccessKey' => 'secret',
                    ],
                ],
                InvalidConfigurationException::class,
                'The child node "#sessionToken" at path "root.parameters" must be configured.',
            ],
        ];
    }
}
