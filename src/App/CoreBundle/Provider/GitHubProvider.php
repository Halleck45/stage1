<?php

namespace App\CoreBundle\Provider;

use App\Model\Build;
use App\Model\Project;
use App\Model\PullRequest;
use Guzzle\Http\Client;
use Psr\Log\LoggerInterface;
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
    public function __construct(LoggerInterface $logger, Client $client, UrlGeneratorInterface $router)
    {
        $client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');

        $this->client = $client;
        $this->router = $router;
    }

    public function createPullRequestFromPayload(Project $project, GithubPayload $payload)
    {
        $json = $payload->getParsedPayload();

        $pr = new PullRequest();
        $pr->setNumber($json->number);
        $pr->setTitle($json->pull_request->title);
        $pr->setRef(sprintf('pull/%d/head', $json->number));
        $pr->setOpen(true);
        $pr->setUrl(sprintf('https://github.com/%s/pull/%d', $project->getFullName(), $json->number));
        $pr->setProject($project);

        return $pr;
    }

    public function getPullRequestHead(Build $build, $separator = ':')
    {
        $project = $build->getProject();

        list($name,) = explode('/', $project->getFullName());

        return $name.$separator.$build->getRef();
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
     * @return array
     */
    public function getCollaborators(Project $project)
    {
        $url = sprintf('/repos/%s/collaborators', $project->getFullName());
        $client = $this->configureClientForProject($project);

        return $client->get($url)->send()->json();
    }

    /**
     * @param Project $project
     * @param Build $build
     * @param string $state
     */
    public function setCommitStatus(Project $project, Build $build, $status)
    {
        $client = $this->configureClientForProject($project);
        $client->setDefaultOption('headers/Accept', 'application/vnd.github.she-hulk-preview+json');

        $request = $client->post(['/repos/'.$project->getFullName().'/statuses/{sha}', [
            'sha' => $build->getHash(),
        ]]);

        $request->setBody(json_encode([
            'state' => 'success',
            'target_url' => $build->getUrl(),
            'description' => 'Stage1 instance ready',
            'context' => 'stage1',
        ]));

        $this->logger->info('sending commit status', [
            'build' => $build->getId(),
            'project' => $project->getGithubFullNAme(),
            'sha' => $build->getHash(),
        ]);

        try {
            $request->send();
        } catch (\Guzzle\Http\Exception\ClientErrorResponseException $e) {
            $this->logger->error('error sending commit status', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'url' => $e->getRequest()->getUrl(),
                'response' => (string) $e->getResponse()->getBody(),
            ]);
        }
    }

    /**
     * @param Project $project
     * @param Build $build
     */
    public function sendPullRequestComment(Project $project, Build $build)
    {
        $client = $this->configureClientForProject($project);

        $request = $client->get(['/repos/'.$project->getFullName().'/pulls{?data*}', [
            'state' => 'open',
            'head' => $this->getPullRequestHead($build, '/')
        ]]);

        $response = $request->send();

        foreach ($response->json() as $pr) {
            $this->logger->info('sending pull request comment', [
                'build' => $build->getId(),
                'project' => $project->getFullName(),
                'pr' => $pr['number'],
                'pr_url' => $pr['html_url']
            ]);

            $commentRequest = $client->post($pr['comments_url']);
            $commentRequest->setBody(json_encode(['body' => 'Stage1 build finished, url: '.$build->getUrl()]));
            $commentRequest->send();
        }
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
    public function pruneDeployKeys(Project $project)
    {
        $keysUrl = $project->getProviderData('keys_url');
        $projectDeployKey = $project->getPublicKey();

        $client = $this->configureClientForProject($project);
        $keys = $client->get($keysUrl)->send()->json();

        foreach ($keys as $key) {
            if ($key['key'] !== $projectDeployKey) {
                $client->delete([$keys_url, ['key_id' => $key['id']]])->send();
            }
        }
    }

    /**
     * @param Project $project
     */
    public function installDeployKey(Project $project)
    {
        if ($this->hasDeployKey($project)) {
            return;
        }

        $client = $this->configureClientForProject($project);

        $request = $client->post($project->getKeysUrl());
        $request->setBody(json_encode([
            'key' => $project->getPublicKey(),
            'title' => 'stage1.io (added by support@stage1.io)',
        ]), 'application/json');

        $response = $request->send();
        $installedKey = $response->json();

        $project->setProviderData('deploy_key_id', $installedKey['id']);
    }

    /**
     * @param Project $project
     * 
     * @return boolean
     */
    public function hasDeployKey(Project $project)
    {
        return $this->countDeployKeys($project) > 0;
    }

    /**
     * @param Project $project
     */
    public function clearHooks(Project $project)
    {
        $client = $this->configureClientForProject($project);
        $hooksUrl = $project->getProviderData('hooks_url');

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

        $hooksUrl = $project->getProviderData('hooks_url');

        $client = $this->configureClientForProject($project);

        $request = $client->post($hooksUrl);
        $request->setBody(json_encode([
            'name' => 'web',
            'active' => true,
            'events' => ['push', 'pull_request'],
            'config' => ['url' => $githubHookUrl, 'content_type' => 'json'],
        ]), 'application/json');

        $response = $request->send();
        $installedHook = $response->json();

        $providerData = $project->setProviderData('hook_id', $installedHook['id']);
    }

    /**
     * @param Project $project
     */
    public function triggerWebHook(Project $project)
    {
        $fullName = $project->getFullName();
        $hookId = $project->getProviderData('hook_id');

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
        $response = $client->get($project->getProviderData('keys_url'))->send();

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
        $response = $client->get($project->getProviderData('hooks_url'))->send();

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
        $response = $client->get($project->getProviderData('hooks_url'))->send();

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