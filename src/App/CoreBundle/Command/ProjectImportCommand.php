<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ProjectImportCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:import')
            ->setDescription('Imports a project')
            ->setDefinition([
                new InputArgument('full_name', InputArgument::REQUIRED, 'The project\'s full name'),
                new InputArgument('user_spec', InputArgument::REQUIRED, 'The user spec'),
                new InputArgument('provider', InputArgument::REQUIRED, 'The provider'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $user = $this->findUser($input->getArgument('user_spec'));
        $provider = $this->getContainer()->get('app_core.provider.factory')->getProviderByName($input->getArgument('provider'));

        $importer = $provider->getImporter();
        $importer->setUser($user);

        $project = $importer->import($input->getArgument('full_name'), function($step) use ($output) {
            $output->writeln('  - '.$step['label'].' ('.$step['id'].')');
        });

        $output->writeln('Imported project <info>'.$project->getFullName().'</info> (id #<info>'.$project->getId().'</info>)');
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