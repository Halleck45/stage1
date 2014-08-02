<?php

namespace App\CoreBundle\Command;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GithubCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:github')
            ->setDescription('Runs requests against the Github API')
            ->setDefinition([
                new InputOption('user', 'u', InputOption::VALUE_REQUIRED, 'User to get an access token from'),
                new InputArgument('path', InputArgument::REQUIRED, 'Path to query')
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('user')) {
            $repo = $this->getContainer()->get('doctrine')->getRepository('Model:User');
            $user = $repo->findOneBySpec($input->getOption('user'));

            if (!$user) {
                throw new \RuntimeException(sprintf('Could not impersonate user "%s"', $input->getOption('user')));
            }

            $output->writeln('impersonating <info>'.$user->getUsername().'</info>');

            $provider = $this->getContainer()->get('app_core.provider.github');
            $client = $provider->configureClientForUser($user);
            $client->setDefaultOption('headers/Authorization', 'token '.$accessToken);
        }

        $request = $client->get($input->getArgument('path'));

        try {
            $response = $request->send();
        } catch (ClientErrorResponseException $e) {
            $response = $e->getResponse();
        }

        $output->writeln(json_encode($response->json(), JSON_PRETTY_PRINT));    
    }
}