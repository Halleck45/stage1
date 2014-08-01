<?php

namespace App\CoreBundle\Provider;

use App\Model\Project;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ProviderFactory
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getProvider(Project $project)
    {
        $providerService = sprintf('app_core.provider.'.$project->getProviderName());

        return $this->container->get($providerService);
    }
}