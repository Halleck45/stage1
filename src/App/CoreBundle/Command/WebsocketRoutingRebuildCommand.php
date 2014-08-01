<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebsocketRoutingRebuildCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:websocket:routing:rebuild')
            ->setDescription('Rebuilds the websocket routing from scratch');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $redis = $this->getContainer()->get('app_core.redis');

        foreach ($redis->keys('channel:routing:*') as $key) {
            $output->writeln('removing routing key <info>'.$key.'</info>');
            $redis->del($key);
        }

        $em = $this->getContainer()->get('doctrine')->getManager();
        $repo = $em->getRepository('Model:User');

        foreach ($repo->findAll() as $user) {
            $output->writeln('rebuilding routing for user <info>'.$user->getUsername().'</info>');
            $key = 'channel:routing:'.$user->getChannel();

            foreach ($user->getProjects() as $project) {
                $output->writeln('adding project <info>'.$project->getFullName().'</info>');
                $redis->sadd($key, $project->getChannel());
            }
        }
    }
}