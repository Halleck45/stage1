<?php

namespace App\CoreBundle\EventListener;

use App\CoreBundle\Provider\ProviderFactory;
use App\Model\Build;
use App\Model\PullRequest;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class BuildPullRequestRelationSubscriber implements EventSubscriber
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RegistryInterface
     */
    private $doctrine;

    /**
     * @var ProviderFactory
     */
    private $providerFactory;

    /**
     * @param LoggerInterface   $logger
     * @param RegistryInterface $doctrine
     * @param ProviderFactory   $providerFactory
     */
    public function __construct(LoggerInterface $logger, RegistryInterface $doctrine, ProviderFactory $providerFactory)
    {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->providerFactory = $providerFactory;
    }

    /**
     * @return string[]
     */
    public function getSubscribedEvents()
    {
        return ['prePersist'];
    }

    /**
     * @param LifecycleEventArgs
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        $build = $args->getEntity();

        if (!$build instanceof Build || !$build->isPullRequest()) {
            return;
        }

        $em = $this->doctrine->getManager();
        
        $pr = $this->doctrine
            ->getRepository('Model:PullRequest')
            ->findOneBy([
                'project' => $build->getProject()->getId(),
                'ref' => $build->getRef(),
            ]);

        if (!$pr) {
            $this->logger->info('creating non-existing pr', [
                'project' => $build->getProject()->getId(),
                'ref' => $build->getRef()
            ]);

            $provider = $this->providerFactory->getProvider($build->getProject());
            $pr = $provider->createPullRequestFromPayload($build->getPayload());
            
            $em->persist($pr);
        }

        $build->setPullRequest($pr);
    }
}