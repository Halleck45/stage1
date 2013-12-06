<?php

namespace App\CoreBundle\EventListener\Build;

use App\CoreBundle\Entity\Build;
use App\CoreBundle\Event\BuildFinishedEvent;

use Docker\Docker;
use Docker\Exception\ContainerNotFoundException;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class ContainersLimitListener
{
    /**
     * @var Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var Symfony\Bridge\Doctrine\RegistryInterface
     */
    private $doctrine;

    /**
     * @var Docker\Docker
     */
    private $docker;

    /**
     * @var integer
     */
    private $limit = 5;

    /**
     * @param Psr\Log\LoggerInterface                   $logger
     * @param Symfony\Bridge\Doctrine\RegistryInterface $doctrine
     * @param Docker\Docker                             $docker
     */
    public function __construct(LoggerInterface $logger, RegistryInterface $doctrine, Docker $docker, $limit)
    {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->docker = $docker;
        $this->limit = $limit;

        $logger->info('initialized '.__CLASS__);
    }

    /**
     * @param App\CoreBundle\Event\BuildFinishedEvent
     */
    public function onBuildFinished(BuildFinishedEvent $event)
    {
        $build = $event->getBuild();

        if (!$build->isRunning()) {
            return;
        }

        $em = $this->doctrine->getManager();
        $buildRepository = $em->getRepository('AppCoreBundle:Build');

        $user = $build->getProject()->getUsers()->first();

        $runningBuilds = $buildRepository->findRunningBuildsByUser($user);

        $this->logger->info('detected running builds for user', [
            'build' => $build->getId(),
            'running_builds' => count($runningBuilds),
            'user' => $user->getUsername(),
        ]);

        // we need all potential changed build status to be flushed
        $em->flush();

        if (count($runningBuilds) <= $this->limit) {
            return;
        }

        $excessBuilds = array_slice($runningBuilds, $this->limit);

        $manager = $this->docker->getContainerManager();

        foreach ($excessBuilds as $excessBuild) {
            $container = $excessBuild->getContainer();

            $this->logger->info('stopping excess container', [
                'build' => $build->getId(),
                'excess_build' => $excessBuild->getId(),
                'excess_container' => $container->getId()
            ]);

            $excessBuild->setStatus(Build::STATUS_STOPPED);
            $excessBuild->setMessage('Per-user running containers limit reached');

            $em->persist($excessBuild);

            try {
                $manager
                    ->stop($container)
                    ->remove($container);
            } catch (ContainerNotFoundException $e) {
                $this->logger->warn('container not found', ['container' => $container->getId()]);
            }
        }
    }
}