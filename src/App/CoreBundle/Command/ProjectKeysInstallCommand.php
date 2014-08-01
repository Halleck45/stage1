<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use InvalidArgumentException;

class ProjectKeysInstallCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:keys:install')
            ->setDescription('Install or reinstalls a project\'s keys')
            ->setDefinition([
                new InputArgument('project_spec', InputArgument::REQUIRED, 'The project\'s spec'),
                new InputOption('delete', 'd', InputOption::VALUE_NONE, 'Delete other existing keys'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->findProject($input->getArgument('project_spec'));
        $provider = $this->getContainer()->get('app_core.provider.factory')->getProvider($project);

        if ($input->getOption('delete')) {
            $provider->clearDeployKeys($project);
        }

        $provider->installDeployKey();
    }

    private function findProject($spec)
    {
        $repository = $this->getContainer()->get('doctrine')->getRepository('Model:Project');

        if (is_numeric($spec)) {
            return $repository->find((integer) $spec);
        }

        $projects = $repository->findBySlug($spec);

        if (count($projects) === 0) {
            throw new InvalidArgumentException('Project not found');
        }

        return $projects[0];
    }
}