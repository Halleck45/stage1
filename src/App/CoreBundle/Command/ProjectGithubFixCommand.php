<?php

namespace App\CoreBundle\Command;

use App\Model\Project;
use App\Model\ProjectSettings;
use App\Model\Organization;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class ProjectGithubFixCommand extends ContainerAwareCommand
{
    private $githubInfos = [];

    public function configure()
    {
        $this
            ->setName('stage1:project:github:fix')
            ->setDescription('Fixes malformed GitHub Project entities');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $repository = $this->getContainer()->get('doctrine')->getRepository('Model:Project');
        $em = $this->getContainer()->get('doctrine')->getManager();

        foreach ($repository->findByProviderName('github') as $project) {
            try {
                $githubInfos = $this->getGithubInfos($project);
            } catch (\Exception $e) {
                $output->writeln('<error>Could not fetch infos for <info>'.$project->getFullName().'</info>');
                continue;
            }

            $providerData = $project->getProviderData();

            if (strlen($providerData['hooks_url']) === 0) {
                $output->writeln('fixing hooks url for <info>'.$project->getFullName().'</info>');
                $providerData['hooks_url'] = $githubInfos['hooks_url'];
            }

            if (strlen($project->getDockerBaseImage()) === 0) {
                $output->writeln('fixing base image for <info>'.$project->getFullName().'</info>');
                $project->setDockerBaseImage('symfony2:latest');
            }

            if (strlen($providerData['url']) === 0) {
                $output->writeln('fixing github url for <info>'.$project->getFullName().'</info>');
                $providerData['url'] = 'https://api.github.com/repos/'.$project->getFullName();
            }

            if (strlen($providerData['private']) === 0) {
                $output->writeln('fixing github private status for <info>'.$project->getFullName().'</info>');
                $providerData['private'] = $githubInfos['private'];
            }

            if (strlen($providerData['contents_url']) === 0) {
                $output->writeln('fixing github contents url for <info>'.$project->getFullName().'</info>');
                if (!isset($githubInfos['contents_url'])) {
                    $output->writeln('<error>could not find a <info>contents_url</info> for <info>'.$project->getFullName().'</info></error>');
                } else {
                    $providerData['contents_url'] = $githubInfos['contents_url'];
                }
            }

            if (null === $project->getOrganization() && isset($githubInfos['organization'])) {
                $output->writeln('fixing organization for <info>'.$project->getFullName().'</info>');

                $orgKeys = $this
                    ->getContainer()
                    ->get('app_core.ssh_keys_generator')
                    ->generate();

                $org = new Organization();
                $org->setName($githubInfos['organization']['login']);
                $org->setGithubId($githubInfos['organization']['id']);
                $org->setPublicKey($orgKeys['public']);
                $org->setPrivateKey($orgKeys['private']);

                $project->setOrganization($org);
            }

            if (!$project->getSettings() || strlen($project->getSettings()->getPolicy()) === 0) {
                $output->writeln('fixing build policy for <info>'.$project->getFullName().'</info>');

                $settings = $project->getSettings() ?: new ProjectSettings();
                $settings->setPolicy(ProjectSettings::POLICY_ALL);
                $settings->setProject($project);

                $em->persist($settings);
            }

            try {
                $githubHookUrl = $this->getContainer()->get('router')->generate('app_core_hooks_github', [], true);
                $githubHookUrl = str_replace('http://localhost', 'http://stage1.io', $githubHookUrl);

                $client = $this->getContainer()->get('app_core.client.github');
                $client->setDefaultOption('headers/Authorization', 'token '.$project->getUsers()->first()->getAccessToken());
                
                if (strlen($providerData['hook_id']) === 0) {
                    $output->writeln('adding webhook for <info>'.$project->getFullName().'</info>');
                    $request = $client->post($providerData['hooks_url']);
                    $request->setBody(json_encode([
                        'name' => 'web',
                        'active' => true,
                        'events' => ['push', 'pull_request'],
                        'config' => ['url' => $githubHookUrl, 'content_type' => 'json'],
                    ]), 'application/json');

                    $response = $request->send();
                    $installedHook = $response->json();

                    $providerData['hook_id'] = $installedHook['id'];
                } else {
                    $request = $client->get([$providerData['hooks_url'], [
                        'id' => $providerData['hook_id'],
                    ]]);

                    $response = $request->send();
                    $installedHook = $response->json()[0];

                    if (count($installedHook['events']) === 1) {
                        $output->writeln('adding pull_request webhook event for <info>'.$project->getFullName().'</info>');
                        $request = $client->patch($installedHook['url']);
                        $request->setBody(json_encode(['add_events' => ['pull_request']]));
                        $request->send();
                    }
                }
            } catch (\Guzzle\Http\Exception\ClientErrorResponseException $e) {
                $output->writeln('<error>could not check webhook for <info>'.$project->getFullName().'</info>');
                echo $e->getResponse()->getBody();
            }

            $project->setProviderData($providerData);

            $em->persist($project);
        }

        $em->flush();
    }

    private function getGithubInfos(Project $project)
    {
        if (!array_key_exists($project->getFullName(), $this->githubInfos)) {
            $client = $this->getContainer()->get('app_core.client.github');
            $client->setDefaultOption('headers/Authorization', 'token '.$project->getUsers()->first()->getAccessToken());
            $client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');
            $request = $client->get($project->getGithubUrl());
            $response = $request->send();

            $this->githubInfos[$project->getFullName()] = $response->json();
        }

        return $this->githubInfos[$project->getFullName()];
    }
}