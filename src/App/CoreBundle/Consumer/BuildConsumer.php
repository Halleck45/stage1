<?php

namespace App\CoreBundle\Consumer;

use App\CoreBundle\Builder\Builder;
use App\Model\Build;
use App\Model\BuildFailure;
use App\CoreBundle\BuildEvents;
use App\CoreBundle\Event\BuildStartedEvent;
use App\CoreBundle\Event\BuildFinishedEvent;
use Docker\Docker;
use Docker\Container;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Exception;


class BuildConsumer implements ConsumerInterface
{
    private $logger;

    private $dispatcher;

    private $doctrine;

    private $builder;

    private $docker;

    private $build;

    private $options = array(
        'builder_host' => null,
        'timeout' => null,
    );

    public function __construct(LoggerInterface $logger, EventDispatcherInterface $dispatcher, RegistryInterface $doctrine, Builder $builder, Docker $docker)
    {
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->doctrine = $doctrine;
        $this->builder = $builder;
        $this->docker = $docker;

        $logger->info('initialized '.__CLASS__, ['pid' => posix_getpid()]);
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

    public function execute(AMQPMessage $message)
    {
        $body = json_decode($message->body);

        $em = $this->doctrine->getManager();
        $buildRepository = $em->getRepository('Model:Build');

        $this->build = $build = $buildRepository->find($body->build_id);

        if (!$build) {
            $this->logger->info('could not find build #'.$body->build_id);
            return;
        }

        if (!$build->isScheduled()) {
            $this->logger->warning('build found but not scheduled', [
                'build' => $build->getId(),
                'status' => $build->getStatus(),
                'status_label' => $build->getStatusLabel()
            ]);

            return;
        }

        // check if there are newer scheduled builds for our ref
        $newerScheduledBuilds = $buildRepository->findNewerScheduledBuilds($build);

        if (count($newerScheduledBuilds) > 0) {
            $this->logger->warning('newer scheduled builds found for this ref', [
                'build' => $build->getId(),
                'ref' => $build->getRef()
            ]);

            $build->setStatus(Build::STATUS_CANCELED);
            $em->persist($build);
            $em->flush();

            return;
        }

        // check for other builds with the same hash
        $sameHashBuilds = $buildRepository->findByHash($build->getHash());

        $allowBuild = true;

        if (count($sameHashBuilds) > 0) {
            $allowBuild = array_reduce($sameHashBuilds, function($result, $b) use($build) {
                /**
                 * - If at least one other build is NOT scheduled, it means it has already been built.
                 *   so we don't want to rebuild the same hash
                 * - Otherwise, we might be the first build of a duplicate serie, so we want to build.
                 */
                return ($b->getId() === $build->getId() || $b->isScheduled()) ? $result : false;
            }, true);
        }


        if (!$allowBuild) {
            $this->logger->warning('aborting build for already built hash', [
                'build' => $build->getId(),
                'hash' => $build->getHash()
            ]);

            $build->setStatus(Build::STATUS_DUPLICATE);
        } else {
            $build->setStatus(Build::STATUS_BUILDING);
            $build->setPid(posix_getpid());

            $em->persist($build);
            $em->flush();

            $this->logger->info('starting build', [
                'pid' => $build->getPid(),
                'builder_host' => $this->getOption('builder_host')
            ]);

            try {
                $this->dispatcher->dispatch(BuildEvents::STARTED, new BuildStartedEvent($build));

                $container = $this->builder->run($build, $this->getOption('timeout'));

                $this->logger->info('builder finished', [
                    'build' => $build->getId(),
                    'container' => ($container instanceof Container ? $container->getId() : '-')
                ]);

                if ($container instanceof Container) {
                    $build->setContainer($container);
                    $build->setPort($container->getMappedPort(80)->getHostPort());                
                }

                $build->setStatus(Build::STATUS_RUNNING);
            } catch (\Docker\Http\Exception\ParseErrorException $e) {
                $this->logger->error('build failed (response parse error)', [
                    'build' => $build->getId(),
                    'exception' => $e
                ]);

                $this->logger->error((string) $e->getRequest());
                $this->logger->error($e->getContent());

                $build->setStatus(Build::STATUS_FAILED);

                // @todo remove this, it's deprecated by BuildFailure
                $build->setMessage(get_class($e).': '.$e->getMessage());

                $failure = BuildFailure::fromException($e);
                $failure->setBuild($build);
                $em->persist($failure);
            } catch (\Docker\Http\Exception\TimeoutException $e) {
                // @todo kill the container if it is still running
                $this->logger->error('build failed (timeout)', [
                    'build' => $build->getId(),
                    'exception' => $e,
                    'trace' => $e->getTraceAsString(),
                ]);
                
                $build->setStatus(Build::STATUS_TIMEOUT);
                $failure = BuildFailure::fromException($e);
                $failure->setBuild($build);
                $em->persist($failure);
            } catch (Exception $e) {
                $this->logger->error('build failed', [
                    'build' => $build->getId(),
                    'exception' => $e,
                    'trace' => $e->getTraceAsString(),
                ]);
                
                $build->setStatus(Build::STATUS_FAILED);
                $build->setMessage(get_class($e).': '.$e->getMessage());

                $failure = BuildFailure::fromException($e);
                $failure->setBuild($build);
                $em->persist($failure);
            }
        }

        /**
         * We run this in a separate try/catch because even if the main build fails
         * we want to let listeners a chance to do something
         */
        try {
            $this->dispatcher->dispatch(BuildEvents::FINISHED, new BuildFinishedEvent($build));            
        } catch (Exception $e) {
            $this->logger->error('build.finished listeners failed', [
                'build' => $build->getId(),
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            $build->setStatus(Build::STATUS_FAILED);
            $build->setMessage(get_class($e).': '.$e->getMessage());            
            $failure = BuildFailure::fromException($e);
            $failure->setBuild($build);
            $em->persist($failure);
        }

        $em->persist($build);
        $em->flush();
    }
}