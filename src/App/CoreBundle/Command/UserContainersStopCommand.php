<?php

namespace App\CoreBundle\Command;

use App\Model\Build;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use InvalidArgumentException;

class UserContainersStopCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:user:containers:stop')
            ->setDescription('Stops all containers for a project')
            ->setDefinition([
                new InputArgument('user_spec', InputArgument::REQUIRED, 'The user\'s spec'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $user = $this->findUser($input->getArgument('user_spec'));

        $output->writeln('found user <info>'.$user->getUsername().'</info>');

        $docker = $this->getContainer()->get('app_core.docker');
        $em = $this->getContainer()->get('doctrine')->getManager();

        $count = 0;

        foreach ($user->getProjects() as $project) {
            $output->writeln('shutting down project <info>'.$project->getFullName().'<info>');

            foreach ($project->getRunningBuilds() as $build) {
                $container = $build->getContainer();

                $output->writeln('  stopping container <info>'.substr($container->getId(), 0, 8).'</info>');

                try {
                    $docker->getContainerManager()
                        ->stop($container)
                        ->remove($container);

                    $count++;                
                } catch (\Exception $e) {
                    $output->writeln('  <error>could not stop container</error>');
                    $output->writeln(sprintf('  [%s] %s', get_class($e), $e->getMessage()));
                }

                $build->setStatus(Build::STATUS_STOPPED);

                $em->persist($build);
            }
        }

        $em->flush();

        $output->write(PHP_EOL);
        $output->writeln('stopped <info>'.$count.'</info> containers');
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