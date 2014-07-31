<?php

namespace App\CoreBundle\Command;

use App\Model\Build;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use InvalidArgumentException;

class ProjectContainersStopCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:containers:stop')
            ->setDescription('Stops all containers for a project')
            ->setDefinition([
                new InputArgument('project_spec', InputArgument::REQUIRED, 'The project\'s spec'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->findProject($input->getArgument('project_spec'));

        $output->writeln('found project <info>'.$project->getFullName().'</info>');

        $docker = $this->getContainer()->get('app_core.docker');
        $em = $this->getContainer()->get('doctrine')->getManager();
        $rp = $em->getRepository('Model:Build');

        $containers = $docker->getContainerManager()->findAll();

        $prefix = 'b/'.$project->getId();
        $prefixLength = strlen($prefix);

        $output->writeln('found <info>'.count($containers).'</info> total running containers');

        $count = 0;

        foreach ($containers as $container) {
            if (substr($container->getImage()->getRepository(), 0, $prefixLength) === $prefix) {

                list(,$projectId,,$buildId) = explode('/', $container->getImage()->getRepository());
                $build = $rp->find($buildId);

                if ($build) {
                    $build->setStatus(Build::STATUS_STOPPED);
                    $em->persist($build);
                }

                $docker->getContainerManager()->stop($container);

                $output->writeln('stopping container <info>'.$container->getId().'</info>');

                $count++;
            }
        }

        $em->flush();

        $output->write(PHP_EOL);
        $output->writeln('stopped <info>'.$count.'</info> containers');
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