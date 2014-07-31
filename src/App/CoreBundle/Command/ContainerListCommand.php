<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ContainerListCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:container:list')
            ->setDescription('Lists running containers')
            ->setDefinition([
                new InputOption('project', 'p', InputOption::VALUE_REQUIRED, 'Filter by project'),
                new InputOption('username', 'u', InputOption::VALUE_REQUIRED, 'Filter by username'),
                new InputOption('build_status', 'b', InputOption::VALUE_REQUIRED, 'Filter by build status'),
                new InputOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort results'),
                new InputOption('container', 'c', InputOption::VALUE_NONE, 'Only display container id'),
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $docker = $this->getContainer()->get('app_core.docker');

        $containers = $docker->getContainerManager()->findAll();

        if (count($containers) === 0) {
            return;
        }

        $em = $this->getContainer()->get('doctrine')->getManager();
        $rp = $em->getRepository('Model:Build');

        $headers = [
            'id' => 'Id',
            'image' => 'Image',
            'container' => 'Cont.',
            'project' => 'Project',
            'branch' => 'Branch',
            'user' => 'User',
            'build-status' => 'Build Status',
            // 'container-status' => 'Cont. Status',
            // 'ssh-port' => 'Ssh Port',
        ];

        if ((null !== $sort = $input->getOption('sort')) && !array_key_exists($sort, $headers)) {
            $output->writeln('<error>Cannot sort by field</error> <info>'.$sort.'<info> <error>.</error>');
            $output->writeln('Available field for sorting:');

            foreach (array_keys($headers) as $field) {
                $output->writeln('  - <info>'.$field.'</info>');
            }

            return;
        }

        $projectFilter = $input->getOption('project');
        $usernameFilter = $input->getOption('username');
        $buildStatusFilter = $input->getOption('build_status');

        $onlyContainer = $input->getOption('container');

        $rows = [];

        foreach ($containers as $container) {
            // list(,$projectId,,$buildId) = explode('/', $container->getImage()->getRepository());
            $build = $rp->findOneByContainerId($container->getId());

            if (!$build) {
                if (!$input->getOption('container')) {
                    $output->writeln('Could not find build for container <info>'.$container->getId().'</info>');
                }
                continue;
            }

            if (null === $build->getProject()) {
                $projectName = '';
            } else {
                $projectName = $build->getProject()->getFullName();
            }

            if (null !== $projectFilter && false === strpos($projectName, $projectFilter)) {
                continue;
            }

            if (null === $build->getProject()) {
                $username = '';
            } else {
                $username = $build->getProject()->getUsers()->first()->getUsername();
            }

            if (null !== $usernameFilter && false === strpos($username, $usernameFilter)) {
                continue;
            }

            $buildStatus = $build->getStatusLabel();

            if (null !== $buildStatusFilter && false === strpos($buildStatus, $buildStatusFilter)) {
                continue;
            }

            $data = $container->getData();

            $label = $build->isPullRequest()
                ? sprintf('pf-%s', $build->getPullRequest()->getNumber())
                : $build->getBranch()->getName();

            $rows[] = [
                $build->getId(),
                explode(':', $container->getImage()->getName())[0],
                (!$onlyContainer && posix_isatty(STDOUT)) ? substr($container->getId(), 0, 8) : $container->getId(),
                $projectName,
                $label,
                $username,
                $build->getStatusLabel(),
                // $data['Status'],
                // isset($data['Ports'][1]) ? $data['Ports'][1]['PublicPort'] : null
            ];
        }

        if (null !== $sort) {
            $sortIndex = array_search($sort, array_keys($headers));
            usort($rows, function($a, $b) use ($sortIndex) {
                if ($a[$sortIndex] === $b[$sortIndex]) {
                    return 0;
                }

                return $a[$sortIndex] > $b[$sortIndex];
            });
        }

        if (count($rows) === 0) {
            return;
        }

        if ($onlyContainer) {
            foreach ($rows as $row) {
                $output->writeln($row[2]);
            }

            return;
        }

        if (posix_isatty(STDOUT)) {
            $table = $this->getHelperSet()->get('table');
            $table->setHeaders(array_values($headers));
            $table->setRows($rows);
            $table->render($output);

            $output->writeln('');
            $output->writeln('Found <info>'.count($rows).'</info> running containers');                        
        } else {
            foreach ($rows as $row) {
                $output->writeln(implode(',', $row));
            }
        }
    }
}