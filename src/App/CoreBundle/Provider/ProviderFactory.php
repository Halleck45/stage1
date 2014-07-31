<?php

namespace App\CoreBundle\Provider;

class ProviderFactory
{
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function getProvider(Project $project)
    {
        $providerService = sprintf('app_core.provider.'.$project->getProviderName());

        return $this->container->get($providerService);
    }
}