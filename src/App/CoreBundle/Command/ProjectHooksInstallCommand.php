<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use InvalidArgumentException;

class ProjectHooksInstallCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:hooks:install')
            ->setDescription('Reinstalls a project\'s hooks')
            ->setDefinition([
                new InputArgument('project_spec', InputArgument::REQUIRED, 'The project\'s spec'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->findProject($input->getArgument('project_spec'));

        $output->writeln('installing hooks for project <info>'.$project->getFullName().'</info>');
        
        $provider = $this->getContainer()->get('app_core.provider.factory')->getProvider($project);

        $provider->clearHooks($project);
        $provider->installHooks($project);

        $em = $this->getContainer()->get('doctrine')->getManager();
        $em->persist($project);
        $em->flush();
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