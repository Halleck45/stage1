<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectAccessResetCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:access:reset')
            ->setDescription('Resets project access tables')
            ->setDefinition([
                new InputOption('public', null, InputOption::VALUE_NONE, 'Only reset public projects'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $redis = $this->getContainer()->get('app_core.redis');
        $em = $this->getContainer()->get('doctrine')->getManager();

        $rp = $em->getRepository('Model:Project');

        if ($input->getOption('public')) {
            $projects = $rp->findByIsPrivate(false);
        } else {
            $projects = $rp->findAll();
        }

        foreach ($projects as $project) {
            $output->writeln('reset access list for project <info>'.$project->getFullName().'</info>');
            $redis->del($project->getAccessList());

            if ($project->getIsPrivate()) {
                $redis->sadd($project->getAccessList(), '0.0.0.0');
            }
        }
    }
}