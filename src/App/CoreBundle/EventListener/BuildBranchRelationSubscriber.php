<?php

namespace App\CoreBundle\EventListener;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\Common\EventSubscriber;
use App\Model\Build;
use App\Model\Branch;
use Psr\Log\LoggerInterface;

/**
 * Whenever a build is created, checks if there exists a corresponding
 * branch record. If not create it. in any case, create a relation between
 * the build and the branch records.
 */
class BuildBranchRelationSubscriber implements EventSubscriber
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
     * @param LoggerInterface   $logger
     * @param RegistryInterface $doctrine
     */
    public function __construct(LoggerInterface $logger, RegistryInterface $doctrine)
    {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
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

        if (!$build instanceof Build || $build->isPullRequest()) {
            return;
        }

        $em = $this->doctrine->getManager();
        
        $branch = $this->doctrine
            ->getRepository('Model:Branch')
            ->findOneByProjectAndName($build->getProject(), $build->getRef());

        if (!$branch) {
            $this->logger->info('creating non-existing branch', [
                'project' => $build->getProject()->getId(),
                'branch' => $build->getRef()
            ]);

            $branch = new Branch();
            $branch->setName($build->getRef());
            $branch->setProject($build->getProject());

            $em->persist($branch);
        }

        $build->setBranch($branch);
    }
}