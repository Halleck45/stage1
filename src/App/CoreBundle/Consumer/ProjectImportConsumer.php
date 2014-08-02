<?php

namespace App\CoreBundle\Consumer;

use App\CoreBundle\Value\ProjectAccess;
use App\CoreBundle\Provider\ProviderFactory;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use OldSound\RabbitMqBundle\RabbitMq\Producer;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class ProjectImportConsumer implements ConsumerInterface
{
    private $logger;

    private $providerFactory;

    private $doctrine;

    private $websocket;

    private $router;

    private $websocketChannel;

    public function __construct(LoggerInterface $logger, ProviderFactory $providerFactory, RegistryInterface $doctrine, Producer $websocket, Router $router)
    {
        $this->logger = $logger;
        $this->providerFactory = $providerFactory;
        $this->doctrine = $doctrine;
        $this->websocket = $websocket;
        $this->router = $router;

        $logger->info('initialized '.__CLASS__, ['pid' => posix_getpid()]);
    }

    /**
     * @param string $route
     */
    private function generateUrl($route, $parameters = array(), $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH)
    {
        return $this->router->generate($route, $parameters, $referenceType);
    }

    public function setWebsocketChannel($channel)
    {
        $this->logger->info('setting websocket channel', [
            'websocket_channel' => $channel,
        ]);

        $this->websocketChannel = $channel;
    }

    public function getWebsocketChannel()
    {
        return $this->websocketChannel;
    }

    /**
     * @param string $event
     */
    private function publish($event, $data = null)
    {
        $this->logger->info('publishing websocket event', [
            'event' => $event,
            'channel' => $this->getWebsocketChannel(),
        ]);

        $message = [
            'event' => $event,
            'channel' => $this->getWebsocketChannel(),
        ];

        if (null !== $data) {
            $message['data'] = $data;
        }

        $this->websocket->publish(json_encode($message));
    }

    public function execute(AMQPMessage $message)
    {
        $this->logger->info('received import request');
        
        $body = json_decode($message->body);

        if (!isset($body->request) || !isset($body->request->full_name)) {
            $this->logger->error('malformed request');
            return;
        }

        $user = $this->doctrine->getRepository('Model:User')->find($body->user_id);
        $provider = $this->providerFactory->getProviderByName($body->provider_name);

        $importer = $provider->getImporter();

        $importer->setUser($user);
        $importer->setInitialProjectAccess(new ProjectAccess($body->client_ip, $body->session_id));

        $this->setWebsocketChannel($user->getChannel());

        $this->logger->info('import request infos', [
            'user_id' => $user->getId(),
            'user_channel' => $user->getChannel(),
            'websocket_channel' => $this->getWebsocketChannel()
        ]);

        $this->publish('import.start', [
            'steps' => $importer->getSteps(),
            'project_full_name' => $body->request->full_name,
            'project_slug' => $body->request->slug,
        ]);

        $that = $this;

        $project = $importer->import($body->request->full_name, function($step) use ($that) {
            $that->publish('import.step', ['step' => $step['id']]);
        });

        if (false === $project) {
            $this->publish('import.finished');
        } else {
            $this->publish('import.finished', [
                // @todo this might not be necessary anymore to pass the websocket token/channel
                'websocket_token' => $importer->getProjectAccessToken(),
                'websocket_channel' => $project->getChannel(),
                'project_slug' => $project->getSlug(),
                'project_full_name' => $project->getFullName(),
                'project_url' => $this->generateUrl('app_core_project_show', ['id' => $project->getId()]),
            ]);
        }
    }
}