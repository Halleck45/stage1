<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use InvalidArgumentException;

class ProjectBranchDedupCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:branch:dedup')
            ->setDescription('Deduplicates projects branches')
            ->setDefinition([
                new InputArgument('project_spec', InputArgument::OPTIONAL, 'The project\'s spec', null),
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force deduplication'),
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

        foreach ($projects as $project) {
            $branches = [];
            $output->writeln('inspecting project <info>'.$project->getFullName().'</info>');
            
            foreach ($project->getBranches() as $branch) {
                if (isset($branches[$branch->getName()])) {
                    $output->writeln('  - marking branch <info>'.$branch->getId().'</info> (<comment>'.$branch->getName().'</comment>) for removal');

                    if (count($branch->getBuilds()) > 0) {
                        $output->writeln('    - moving <info>'.count($branch->getBuilds()).'</info> build(s)');

                        foreach ($branch->getBuilds() as $build) {
                            $build->setBranch($branches[$branch->getName()]);
                            $em->persist($build);
                        }
                    }

                    $em->remove($branch);
                }

                $branches[$branch->getName()] = $branch;
            }            
        }


        if ($input->getOption('force')) {
            $em->flush();            
        } else {
            $output->writeln('<error>Use the --force if you really mean it.</error>');
        }
    }
}