<?php

namespace App\CoreBundle\Provider;

use App\CoreBundle\Provider\Value\Branch;
use App\Model\Project;
use Guzzle\Http\Client;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * App\CoreBundle\Provider\GitHubProvider
 */
class GitHubProvider
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var UrlGeneratorInterface
     */
    private $router;

    /**
     * @param Client $client
     */
    public function __construct(Client $client, UrlGeneratorInterface $router)
    {
        $client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');

        $this->client = $client;
        $this->router = $router;
    }

    /**
     * @param Project $project
     */
    private function configureClientForProject(Project $project)
    {
        $this->client->setDefaultOption('headers/Authorization', 'token '.$project->getUsers()->first()->getAccessToken());

        return $this->client;
    }

    /**
     * @param Project $project
     * 
     * @return Branch[]
     */
    public function getBranches(Project $project)
    {
        $client = $this->configureClientForProject($project);
        $url = sprintf('/repos/%s/branches', $project->getFullName());

        $branches = [];

        foreach ($client->get($url)->send()->json() as $row) {
            $branches[] = $row['name'];
        }

        return $branches;
    }

    /**
     * @param Project $project
     */
    public function clearHooks(Project $project)
    {
        $client = $this->configureClientForProject($project);
        $hooksUrl = $project->getProviderData()['hooks_url'];

        $response = $client->get($hooksUrl)->send();

        foreach ($response->json() as $hook) {
            if ($hook['name'] === 'web' && strpos($hook['config']['url'], 'stage1.io') !== false) {
                $client->delete($hook['url'])->send();
            }
        }
    }

    /**
     * @param Project $project
     */
    public function installHooks(Project $project)
    {   
        $githubHookUrl = $this->router->generate('app_core_hooks_github', [], true);
        $githubHookUrl = str_replace('http://localhost', 'http://stage1.io', $githubHookUrl);

        $hooksUrl = $project->getProviderData()['hooks_url'];

        $client = $this->configureClientForProject($project);
        
        $request = $client->post($hooksUrl);
        $request->setBody(json_encode([
            'name' => 'web',
            'active' => true,
            'events' => ['push', 'pull_request'],
            'config' => ['url' => $githubHookUrl, 'content_type' => 'json'],
        ]), 'application/json');

        $response = $request->send();
        $installedHook = $response->json()

        $providerData = $project->getProviderData();
        $providerData['hook_id'] = $installedHook['id'];
        $project->setProviderData($providerData);
    }

    /**
     * @param Project $project
     */
    public function triggerWebHook(Project $project)
    {
        $fullName = $project->getFullName();
        $hookId = $project->getProviderData()['hook_id'];

        $url = sprintf('/repos/%s/hooks/%s/tests', $fullName, $hookId);

        $client = $this->configureClientForProject($project);
        $client->post($url)->send();
    }

    /**
     * @param Project $project
     * @param string  $ref
     *
     * @return string
     * 
     * @deprecated ?
     */
    public function getHashFromRef(Project $project, $ref)
    {
        $url = sprintf('/repos/%s/git/refs/heads', $project->getFullName());

        $client = $this->configureClientForProject($project);
        $remoteRefs = $client->get($url)->send()->json();
     
        foreach ($remoteRefs as $remoteRef) {
            if ('refs/heads/'.$ref === $remoteRef['ref']) {
                $hash = $remoteRef['object']['sha'];
                break;
            }
        }

        return $hash;
    }

    /**
     * @param Project $project
     * 
     * @return integer
     */
    public function countDeployKeys(Project $project)
    {
        $client = $this->configureClientForProject($project);
        $response = $client->get($project->getProviderData()['keys_url'])->send();

        $count = 0;

        foreach ($response->json() as $deployKey) {
            if ($deployKey['key'] === $project->getPublicKey()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param Project $project
     * 
     * @return integer
     */
    public function countPushHooks(Project $project)
    {
        $client = $this->configureClientForProject($project);
        $response = $client->get($project->getProviderData()['hooks_url'])->send();

        $count = 0;

        foreach ($response->json() as $hook) {
            if ($hook['name'] === 'web' && strpos($hook['config']['url'], 'stage1.io') !== false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param Project $project
     * 
     * @return integer
     */
    public function countPullRequestHooks(Project $project)
    {
        $client = $this->configureClientForProject($project);
        $response = $client->get($project->getProviderData()['hooks_url'])->send();

        $count = 0;

        foreach ($response->json() as $hook) {
            if ($hook['name'] === 'web' && strpos($hook['config']['url'], 'stage1.io') !== false) {
                foreach ($hook['events'] as $event) {
                    if ($event === 'pull_request') {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }
}