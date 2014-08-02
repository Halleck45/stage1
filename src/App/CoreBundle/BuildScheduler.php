<?php

namespace App\CoreBundle;

use App\Model\Build;
use App\Model\Project;
use App\Model\GithubPayload;
use App\CoreBundle\Message\MessageFactory;
use App\CoreBundle\Scheduler\SchedulerInterface;
use OldSound\RabbitMqBundle\RabbitMq\Producer;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * App\CoreBundle\BuildScheduler
 */
class BuildScheduler
{
    private $doctrine;

    private $buildProducer;

    private $scheduler;

    private $websocketProducer;

    private $messageFactory;

    private $options = array('builder_host_allow' => null);

    public function __construct(LoggerInterface $logger, RegistryInterface $doctrine, Producer $buildProducer, SchedulerInterface $scheduler, Producer $websocketProducer, MessageFactory $messageFactory)
    {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->buildProducer = $buildProducer;
        $this->scheduler = $scheduler;
        $this->websocketProducer = $websocketProducer;
        $this->messageFactory = $messageFactory;
    }

    /**
     * @param string $name
     * @param mixed  $value
     * 
     * @return App\CoreBundle\Builder\Builder
     */
    public function setOption($name, $value)
    {
        $this->options[$name] = $value;
    }

    /**
     * @param string $name
     * 
     * @return mixed
     */
    public function getOption($name)
    {
        return $this->options[$name];
    }

    /**
     * Schedules a build
     * 
     * @see App\CoreBundle\EventListener\BuildBranchRelationSubscriber for automatic creation of non-existing branches
     */
    public function schedule(Project $project, $ref, $hash, GithubPayload $payload = null, $options = [])
    {
        $logger = $this->logger;
        $logger->info('scheduling build', ['project' => $project->getId(), 'ref' => $ref, 'hash' => $hash]);

        $em = $this->doctrine->getManager();

        // @todo I guess this should be in a build.scheduled event listener
        $alreadyRunningBuilds = $em->getRepository('Model:Build')->findPendingByRef($project, $ref);

        foreach ($alreadyRunningBuilds as $build) {
            // @todo instead of retrieving then updating builds to be canceled, directly issue an UPDATE
            //       it should avoid most race conditions
            if ($build->isScheduled()) {
                $logger->info('canceling same ref build', ['ref' => $ref, 'canceled_build' => $build->getId()]);
                $build->setStatus(Build::STATUS_CANCELED);
                $em->persist($build);
                $em->flush();
            } else {
                $logger->info('killing same ref build', ['ref' => $ref, 'canceled_build' => $build->getId()]);
                $scheduler->kill($build);
            }
        }

        $build = new Build();
        $build->setProject($project);
        $build->setStatus(Build::STATUS_SCHEDULED);
        $build->setRef($ref);
        $build->setHash($hash);
        $build->setCommitUrl(sprintf('https://github.com/%s/commit/%s', $project->getFullName(), $hash));

        if (null !== $payload) {            
            $build->setPayload($payload);
        }

        if (isset($options['force_local_build_yml']) && $options['force_local_build_yml']) {
            $build->setForceLocalBuildYml(true);
        }

        $builderHost = null;

        $logger->info('electing builder', [
            'builder_host_allow' => $this->getOption('builder_host_allow'),
        ]);

        if (count($builderHostAllow = $this->getOption('builder_host_allow')) > 0) {
            $builderHost = $builderHostAllow[array_rand($builderHostAllow)];
        }

        $build->setBuilderHost($builderHost);

        /**
         * @todo move this outside, it belongs in a controller
         *       this will allow to remove the $options argument
         */
        $em->persist($build);
        $em->flush();

        $this->logger->info('sending build order', [
            'build' => $build->getId(),
            'builder_host' => $builderHost
        ]);

        $this->buildProducer->publish(json_encode([
            'build_id' => $build->getId()
        ]), $build->getRoutingKey());

        $message = $this->messageFactory->createBuildScheduled($build);
        $this->websocketProducer->publish($message);

        return $build;
    }
}
