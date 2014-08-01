<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectFixProviderCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this->setName('stage1:project:fix-provider');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $doctrine = $this->getContainer()->get('doctrine');

        $repository = $doctrine->getRepository('Model:Project');
        $manager = $doctrine->getManager();

        foreach ($repository->findAll() as $project) {
            try {
                if ($this->fixProvider($output, $project)) {
                    $manager->persist($project);
                }
            } catch (\Exception $e) {
                $output->writeln('<error>could not fix provider for project "'.$project->getGithubFullName().'</error>');
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }
        }

        $manager->flush();
    }

    private function fixProvider(OutputInterface $output, Project $project)
    {
        if (strlen($project->getProviderName()) > 0) {
            $output->writeln('project <info>'.$project->getFullName().'</info> already migrated');
            return false;
        }

        $project->setProviderName('github');
        $project->setProviderData([
            'clone_url' => $project->getCloneUrl(),
            'ssh_url' => $project->getSshUrl(),
            'keys_url' => $project->getKeysUrl(),
            'hooks_url' => $project->getHooksUrl(),
            'contents_url' => $project->getContentsUrl(),
            'id' => $project->getGithubId(),
            'full_name' => $project->getGithubFullName(),
            'owner_login' => $project->getOwnerLogin(),
            'hook_id' => $project->getHookId(),
            'deploy_key_id' => $project->getDeployKeyId(),
            'private' => $project->getGithubPrivate(),
            'url' => $project->getGithubUrl(),
        ]);
    }
}