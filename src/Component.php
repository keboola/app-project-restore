<?php

declare(strict_types=1);

namespace Keboola\App\ProjectRestore;

use Keboola\Component\BaseComponent;

class Component extends BaseComponent
{
    public function run(): void
    {
        /** @var Config $config */
        $config = $this->getConfig();
        $application = new Application($config, $this->getLogger());
        $application->run();
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }
}
