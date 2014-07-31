<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectBranchPruneCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:branch:prune')
            ->setDescription('Prune deleted branches')
            ->setDefinition([
                new InputArgument('project_spec', InputArgument::OPTIONAL, 'The project spec'),
                new InputOption('force', null, InputOption::VALUE_NONE, 'Really do it'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this
            ->getContainer()
            ->get('doctrine')
            ->getManager();

        $rp = $em->getRepository('Model:Project');

        if (null === $input->getArgument('project_spec')) {
            $projects = $rp->findAll();
        } else {
            $project = $rp->findOneBySpec($input->getArgument('project_spec'));

            if (!$project) {
                throw new InvalidArgumentException('Project not found "' . $input->getArgument('project_spec').'"');
            }

            $projects = [$project];            
        }

        $providerFactory = $this->getContainer()->get('app_core.provider.factory');

        foreach ($projects as $project) {
            $output->writeln('inspecting project <info>'.$project->getFullName().'</info>');
            $provider = $providerFactory->getProvider($project);

            $existingBranches = $provider->getBranches($project);

            foreach ($project->getActiveBranches() as $branch) {
                if (false === array_search($branch->getName(), $existingBranches)) {
                    $output->writeln('  - marking branch <info>'.$branch->getName().'</info> as deleted');
                    $branch->setDeleted(true);
                    $em->persist($branch);
                }
            }
        }

        if ($input->getOption('force')) {
            $em->flush();            
        } else {
            $output->writeln('<error>Use the --force if you really mean it.</error>');
        }
    }
}