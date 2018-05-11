<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
            ->children()
                ->scalarNode('backupUri')
                    ->isRequired()
                ->end()
                ->booleanNode('useDefaultBackend')
                    ->defaultValue(false)
                ->end()
                ->scalarNode('accessKeyId')
                    ->isRequired()
                ->end()
                ->scalarNode('#secretAccessKey')
                    ->isRequired()
                ->end()
                ->scalarNode('#sessionToken')
                    ->isRequired()
                ->end()
            ->end()
        ;
        // @formatter:on
        return $parametersNode;
    }
}
