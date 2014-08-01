<?php

namespace App\CoreBundle\EventListener;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\Common\EventSubscriber;
use Psr\Log\LoggerInterface;
use App\Model\Project;
use Redis;

class ProjectAccessTableSubscriber implements EventSubscriber
{
    private $redis;

    public function __construct(Redis $redis, LoggerInterface $logger)
    {
        $this->redis = $redis;
        $this->logger = $logger;
    }

    public function getSubscribedEvents()
    {
        return ['preRemove'];
    }

    public function preRemove(LifecycleEventArgs $args)
    {
        $project = $args->getEntity();

        if (!$project instanceof Project) {
            return;
        }

        if (!$project->getIsPrivate()) {
            return;
        }

        if (!$project instanceof Project) {
            return;
        }

        $this->logger->info('removing auth table for "'.$project->getSlug().'"');
        $this->redis->del('auth:'.$project->getSlug());
    }
}