<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

class UserFixCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:user:fix')
            ->setDescription('Fixes malformed User entities');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $repository = $this->getContainer()->get('doctrine')->getRepository('Model:User');
        $em = $this->getContainer()->get('doctrine')->getManager();

        $env = $this->getContainer()->getParameter('kernel.environment');

        /** @deprecated */
        $client = $this->getContainer()->get('app_core.client.github');
        $client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');

        foreach ($repository->findAll() as $user) {
            $client->setDefaultOption('headers/Authorization', 'token '.$user->getAccessToken());

            if ($env === 'prod' && strlen($user->getChannel(true)) === 0) {
                $output->writeln('generating channel for <info>'.$user->getUsername().'</info>');
                $user->setChannel(uniqid(mt_rand(), true));
            }

            if ($env === 'dev' && strlen($user->getChannel(true)) > 0) {
                $output->writeln('nulling channel for <info>'.$user->getUsername().'</info>');
                $user->setChannel(null);
            }

            /** @deprecated */
            if (strlen($user->getEmail()) === 0) {
                $output->write('fixing email for <info>'.$user->getUsername().'</info> ');

                try {
                    $request = $client->get('/user/emails');
                    $response = $request->send();

                    foreach ($response->json() as $email) {
                        if ($email['primary']) {
                            $user->setEmail($email['email']);
                            break;
                        }
                    }                    
                } catch (Exception $e) {
                    $output->write('<error>failed</error>');
                }

                $output->writeln('');
            }

            if (strlen($user->getPublicKey()) === 0) {
                $output->writeln('generating ssh keys for <info>'.$user->getUsername().'</info>');
                
                $keys = $this
                    ->getContainer()
                    ->get('app_core.ssh_keys_generator')
                    ->generate();

                $user->setPublicKey($keys['public']);
                $user->setPrivateKey($keys['private']);
            }

            if (null === $user->getRoles(true) || count($user->getRoles()) === 0) {
                $output->writeln('setting default role for <info>'.$user->getUsername().'</info>');
                $user->setRoles(['ROLE_USER']);
            }

            if (strlen($user->getAccessToken()) > 0 && count($user->getProvidersAccessTokens()) === 0) {
                $user->setProviderAccessToken('github', $user->getAccessToken());
            }

            if (strlen($user->getAccessTokenScope()) > 0 && count($user->getProvidersScopes()) === 0) {
                $user->setProviderScopes(explode(',', $user->getAccessTokenScope()));
            }

            $em->persist($user);
        }

        $em->flush();
    }
}