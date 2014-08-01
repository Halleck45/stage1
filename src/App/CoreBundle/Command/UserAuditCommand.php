<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class UserAuditCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:user:audit')
            ->setDescription('Displays various information about a user')
            ->setDefinition([
                new InputArgument('user_spec', InputArgument::REQUIRED, 'The user spec'),
                new InputOption('github', null, InputOption::VALUE_NONE, 'Display github information too'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->getContainer()->get('doctrine')->getManager();
        $repo = $em->getRepository('Model:User');

        $user = $repo->findOneBySpec($input->getArgument('user_spec'));

        $infos = [];
        $infos['id'] = $user->getId();
        $infos['username'] = $user->getUsername();
        $infos['email'] = $user->getEmail();
        $infos['token'] = $user->getAccessToken();
        $infos['running_builds'] = count($em->getRepository('Model:Build')->findRunningBuildsByUser($user));
        $infos['created_at'] = $user->getCreatedAt()->format('r');

        $infos['projects'] = [];

        foreach ($user->getProjects() as $project) {
            $infos['projects'][] = $project->getFullName();
        }

        $content = Yaml::dump($infos, 10);
        $content = preg_replace('/^(\s*)([^:\n]+)(:)/m', '\\1<info>\\2</info>\\3', $content);
        $content = preg_replace('/^([^:-]+)(-|:) ([^\n]+)$/m', '\\1\\2 <comment>\\3</comment>', $content);

        $output->writeln($content);

        if ($input->getOption('github')) {
            $client = $this->getContainer()->get('app_core.client.github');
            $client->setDefaultOption('headers/Authorization', 'token '.$user->getAccessToken());
            $client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');

            $request = $client->get('/user');
            $response = $request->send();

            $output->writeln(json_encode($response->json(), JSON_PRETTY_PRINT));
        }
    }
}