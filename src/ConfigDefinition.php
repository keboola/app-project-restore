<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
            ->validate()
                ->always(function ($v) {
                    if (!empty($v['abs']) && !empty($v['s3'])) {
                        throw new InvalidConfigurationException('Both ABS and S3 cannot be set together.');
                    }
                    if (empty($v['abs']) && empty($v['s3'])) {
                        throw new InvalidConfigurationException('ABS or S3 must be configured.');
                    }
                    return $v;
                })
            ->end()
            ->children()
                ->booleanNode('useDefaultBackend')
                    ->defaultValue(false)
                ->end()
                ->booleanNode('restoreConfigs')->defaultTrue()->end()
                ->arrayNode('abs')
                    ->children()
                        ->scalarNode('container')->isRequired()->end()
                        ->scalarNode('#connectionString')->isRequired()->end()
                    ->end()
                ->end()
                ->arrayNode('s3')
                    ->children()
                        ->scalarNode('backupUri')->isRequired()->end()
                        ->scalarNode('accessKeyId')->isRequired()->end()
                        ->scalarNode('#secretAccessKey')->isRequired()->end()
                        ->scalarNode('#sessionToken')->isRequired()->end()
                    ->end()
                ->end()
            ->end()
        ;
        // @formatter:on
        return $parametersNode;
    }
}
