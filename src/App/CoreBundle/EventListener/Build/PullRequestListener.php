<?php

namespace App\CoreBundle\EventListener\Build;

use App\CoreBundle\Event\BuildFinishedEvent;
use App\CoreBundle\Provider\ProviderFactory;
use App\Model\Build;
use Psr\Log\LoggerInterface;

class PullRequestListener
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ProviderFactory
     */
    private $providerFactory;

    /**
     * @var boolean
     */
    private $enabled;

    /**
     * @param LoggerInterface   $logger
     * @param ProviderFactory   $providerFactory
     * @param Docker\Docker
     */
    public function __construct(LoggerInterface $logger, ProviderFactory $providerFactory, $enabled)
    {
        $this->logger = $logger;
        $this->providerFactory = $providerFactory;
        $this->enabled = $enabled;

        $logger->info('initialized '.__CLASS__);
    }

    public function onBuildFinished(BuildFinishedEvent $event)
    {
        if (!$this->enabled) {
            return;
        }
        
        $build = $event->getBuild();

        if (!$build->isRunning()) {
            return;
        }

        $project = $build->getProject();
        $provider = $this->providerFactory->getProvider($project);

        $provider->sendPullRequestComment($project, $build);
    }
}