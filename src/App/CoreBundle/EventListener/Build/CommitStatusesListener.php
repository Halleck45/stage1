<?php

namespace App\CoreBundle\EventListener\Build;

use App\CoreBundle\Event\BuildFinishedEvent;
use App\CoreBundle\Provider\ProviderFactory;
use App\Model\Build;
use Psr\Log\LoggerInterface;

use Exception;

class CommitStatusesListener
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
     * @param boolean           $enabled
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
            $this->logger->info('skipping commit status for non-running build');
            return;
        }

        if (strlen($build->getHash()) === 0) {
            $this->logger->info('skipping commit status because of empty commit hash');
            return;
        }

        $project = $build->getProject();

        if (null === $project) {
            $this->logger->info('could not find a project for build', ['build_id' => $build->getId()]);
            return;
        }

        $provider = $this->providerFactory->getProvider($project);
        $provider->setCommitStatus($project, $build, 'success');
    }
}