<?php

namespace App\CoreBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use InvalidArgumentException;

class ProjectAuditCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:project:audit')
            ->setDescription('Retrieves information about a project')
            ->setDefinition([
                new InputArgument('project', InputArgument::REQUIRED, 'The project\'s id or slug')
            ]);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $input->getArgument('project');

        $infos = [];

        $project = $this->findProject($input->getArgument('project'));

        if (!$project) {
            $output->writeln('<error>Could not find project</error>');
            exit(1);
        }

        $infos['name'] = $project->getFullName();
        $infos['private'] = $project->getIsPrivate();
        $infos['provider_data'] = $project->getProviderData();
        $infos['users'] = $project->getUsers()->map(function($user) { return $user->getUsername(); })->toArray();
        $infos['branches'] = $project->getBranches()->map(function($branch) { return $branch->getName(); })->toArray();

        $infos['builds'] = array(
            'total' => count($project->getBuilds()),
            'running' => count($project->getRunningBuilds()),
            'building' => count($project->getBuildingBuilds()),
        );

        $provider = $this->getContainer()->get('app_core.provider.factory')->getProvider($project);

        $infos['has_deploy_key'] = $provider->countDeployKeys($project);
        $infos['has_hook'] = $project->countPushHooks($project);
        $infos['has_pr_hook'] = $project->countPullRequests($project);

        $infos['tokens'] = [];

        foreach ($project->getUsers() as $user) {
            $infos['tokens'][$user->getUsername()] = $user->getAccessToken();
        }

        $content = Yaml::dump($infos, 10);
        $content = preg_replace('/^(\s*)([^:\n]+)(:)/m', '\\1<info>\\2</info>\\3', $content);
        $content = preg_replace('/^([^:-]+)(-|:) ([^\n]+)$/m', '\\1\\2 <comment>\\3</comment>', $content);

        $output->writeln($content);
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