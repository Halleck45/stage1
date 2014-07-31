<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Model\Project;
use InvalidArgumentException;

class BuildScheduleCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:build:schedule')
            ->setDescription('Schedules a build')
            ->setDefinition([
                new InputArgument('project_spec', InputArgument::REQUIRED, 'The project'),
                new InputArgument('ref', InputArgument::REQUIRED, 'The ref'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->getContainer()->get('doctrine')->getManager();

        $project = $em
            ->getRepository('Model:Project')
            ->findOneBySpec($input->getArgument('project_spec'));

        if (!$project) {
            throw new InvalidArgumentException('project not found');
        }

        $build = $this
            ->getContainer()
            ->get('app_core.build_scheduler')
            ->schedule($project, $input->getArgument('ref'), null);

        $output->writeln('scheduled build <info>'.$build->getId().'</info>');
    }
}