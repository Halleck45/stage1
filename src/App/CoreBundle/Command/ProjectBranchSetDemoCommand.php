<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ProjectBranchSetDemoCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:branch:set-demo')
            ->setDescription('Sets the demo status of a branch')
            ->setDefinition([
                new InputArgument('project_spec', InputArgument::REQUIRED, 'The project spec'),
                new InputArgument('branch_spec', InputArgument::REQUIRED, 'The branch spec'),
                new InputOption('unset', null, InputOption::VALUE_NONE, 'Unset demo status'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this
            ->getContainer()
            ->get('doctrine')
            ->getRepository('Model:Project')
            ->findOneBySpec($input->getArgument('project_spec'));

        $branch = $this
            ->getContainer()
            ->get('doctrine')
            ->getRepository('Model:Branch')
            ->findOneByProjectAndName($project, $input->getArgument('branch_spec'));

        $isDemo = !$input->getOption('unset');
        $branch->setIsDemo($isDemo);

        $em = $this
            ->getContainer()
            ->get('doctrine')
            ->getManager();

        $em->persist($branch);
        $em->flush();

        if ($isDemo) {
            $output->writeln('<info>set</info> demo status for branch <info>'.$project->getFullName().':'.$branch->getName().'</info>');
        } else {
            $output->writeln('<info>unset</info> demo status for branch <info>'.$project->getFullName().':'.$branch->getName().'</info>');
        }
    }
}