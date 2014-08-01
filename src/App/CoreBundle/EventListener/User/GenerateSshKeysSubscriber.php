<?php

namespace App\CoreBundle\EventListener\User;

use App\CoreBundle\SshKeysGenerator;
use App\Model\User;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

class GenerateSshKeysSubscriber implements EventSubscriber
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SshKeysGenerator
     */
    private $sshKeysGenerator;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger, SshKeysGenerator $sshKeysGenerator)
    {
        $this->logger = $logger;
        $this->sshKeysGenerator = $sshKeysGenerator;

        $logger->info('initialized '.__CLASS__);
    }

    /**
     * @return array
     */
    public function getSubscribedEvents()
    {
        return ['prePersist'];
    }

    /**
     * @param mixed $entity
     * 
     * @return boolean
     */
    public function supports($entity)
    {
        return $entity instanceof User;
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        $user = $args->getEntity();

        if (!$this->supports($user)) {
            return;
        }

        $keys = $this->sshKeysGenerator->generate();

        $user->setPublicKey($keys['public']);
        $user->setPrivateKey($keys['private']);
    }
}