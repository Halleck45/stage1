<?php

namespace App\CoreBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

class UserProjectDiscoverCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:user:project:discover')
            ->setDescription('Discovers a user\'s projects')
            ->setDefinition([
                new InputArgument('user_spec', InputArgument::REQUIRED, 'The user'),
                new InputArgument('provider', InputArgument::REQUIRED, 'The provider to discover'),
                new InputOption('all', null, InputOption::VALUE_NONE, 'Also show non-importable projects'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $user = $this->findUser($input->getArgument('user_spec'));

        $provider = $this->getContainer()->get('app_core.provider.factory')->getProviderByName($input->getArgument('provider'));
        $discover = $provider->getDiscover();
        
        $discover->discover($user);

        $output->writeln('');
        $output->writeln('<comment>Importable projects</comment>');

        foreach ($discover->getImportableProjects() as $fullName => $project) {
            $output->writeln('found project <info>'.$fullName.'</info>');
        }

        if ($input->getOption('all')) {
            $output->writeln('');
            $output->writeln('<comment>Non importable projects</comment>');

            foreach ($discover->getNonImportableProjects() as $project) {
                $output->writeln('found non-importable project <info>'.$project['fullName'].'</info> ('.$project['reason'].')');
            }
        }
    }

    private function findUser($spec)
    {
        $repository = $this->getContainer()->get('doctrine')->getRepository('Model:User');

        if (is_numeric($spec)) {
            return $repository->find((integer) $spec);
        }

        $users = $repository->findByUsername($spec);

        if (count($users) === 0) {
            throw new InvalidArgumentException('User not found');
        }

        return $users[0];
    }
}