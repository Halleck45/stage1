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

    public function getProviderByName($name)
    {
        $providerService = sprintf('app_core.provider.'.$name);

        return $this->container->get($providerService);
    }

    public function getProvider(Project $project)
    {
        return $this->getProviderByName($project->getProviderName());
    }
}