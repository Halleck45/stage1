<?php

namespace App\CoreBundle\Github;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Guzzle\Http\Client;
use App\CoreBundle\SshKeysGenerator;
use App\Model\User;
use App\Model\Project;
use App\Model\ProjectSettings;
use App\Model\Branch;
use App\Model\PullRequest;
use App\Model\Organization;
use App\CoreBundle\Value\ProjectAccess;
use Psr\Log\LoggerInterface;
use Closure;
use Redis;

class Import
{
    private $logger;

    private $client;

    private $doctrine;

    private $user;

    private $redis;

    private $router;

    private $sshKeysGenerator;

    private $accessToken;

    private $initialProjectAccess;

    private $projectAccessToken;

    private $feature_ip_access_list = false;

    private $feature_token_access_list = true;

    private $delete_old_keys = false;

    public function __construct(LoggerInterface $logger, Client $client, RegistryInterface $doctrine, Redis $redis, Router $router, SshKeysGenerator $sshKeysGenerator)
    {
        $this->logger = $logger;
        $this->client = $client;
        $this->doctrine = $doctrine;
        $this->redis = $redis;
        $this->router = $router;
        $this->sshKeysGenerator = $sshKeysGenerator;

        $this->client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getSteps()
    {
        return [
            ['id' => 'inspect', 'label' => 'Inspecting project'],
            ['id' => 'keys', 'label' => 'Generating keys'],
            ['id' => 'deploy_key', 'label' => 'Adding deploy key'],
            ['id' => 'webhook', 'label' => 'Configuring webhook'],
            ['id' => 'branches', 'label' => 'Importing branches'],
            ['id' => 'pull_requests', 'label' => 'Importing pull requests'],
            ['id' => 'access', 'label' => 'Granting default access'],
        ];
    }

    public function setInitialProjectAccess(ProjectAccess $initialProjectAccess)
    {
        $this->initialProjectAccess = $initialProjectAccess;
    }

    public function getProjectAccessToken()
    {
        return $this->projectAccessToken;
    }

    public function setFeatureIpAccessList($bool)
    {
        $this->feature_ip_access_list = (bool) $bool;
    }

    public function setFeatureTokenAccessList($bool)
    {
        $this->feature_token_access_list = (bool) $bool;
    }

    /**
     * @param string $accessToken
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        $this->client->setDefaultOption('headers/Authorization', 'token '.$accessToken);
    }

    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;

        if (strlen($user->getAccessToken()) > 0) {
            $this->setAccessToken($user->getAccessToken());
        }
    }

    public function import($githubFullName, Closure $callback = null)
    {
        $project = new Project();
        $project->setGithubFullName($githubFullName);

        if (null === $callback) {
            $callback = function() {};
        }

        $callback(['id' => 'inspect', 'label' => 'Inspecting project']);
        $this->logger->debug('running inspect step', ['project' => $githubFullName]);

        if (false === $this->doInspect($project)) {
            return false;
        }

        $callback(['id' => 'keys', 'label' => 'Generating keys']);
        $this->logger->debug('running keys step', ['project' => $githubFullName]);
        $this->doKeys($project);

        $callback(['id' => 'deploy_key', 'label' => 'Adding deploy key']);
        $this->logger->debug('running deploy_key step', ['project' => $githubFullName]);
        $this->doDeployKey($project);

        $callback(['id' => 'webhook', 'label' => 'Configuring webhook']);
        $this->logger->debug('running webhook step', ['project' => $githubFullName]);
        $this->doWebhook($project);

        $callback(['id' => 'branches', 'label' => 'Importing branches']);
        $this->logger->debug('running branches step', ['project' => $githubFullName]);
        $this->doBranches($project);

        $callback(['id' => 'pull_requests', 'label' => 'Importing pull requests']);
        $this->logger->debug('running pull requests step', ['project' => $githubFullName]);
        $this->doPullRequests($project);

        $callback(['id' => 'access', 'label' => 'Granting default access']);
        $this->logger->debug('running access step', ['project' => $githubFullName]);
        $this->doAccess($project);

        # set default build policy
        $settings = new ProjectSettings();
        $settings->setProject($project);
        $settings->setPolicy(ProjectSettings::POLICY_ALL);

        $em = $this->doctrine->getManager();
        
        $em->persist($project);
        $em->flush();

        $em->persist($settings);
        $em->flush();

        return $project;
    }

    /**
     * @todo @project_access refactor
     */
    private function grantProjectAccess(Project $project, ProjectAccess $access)
    {
        $args = ['auth:'.$project->getSlug()];

        if ($this->feature_ip_access_list || $access->getIp() === '0.0.0.0') {
            $args[] = $access->getIp();
        }

        if ($this->feature_token_access_list) {
            $args[] = $access->getToken();
        }

        $args = array_filter($args, function($arg) { return strlen($arg) > 0; });

        return call_user_func_array([$this->redis, 'sadd'], $args);
    }

    /**
     * @param string $route
     * @param boolean $referenceType
     */
    private function generateUrl($route, $parameters = array(), $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH)
    {
        return $this->router->generate($route, $parameters, $referenceType);
    }

    # @todo use github api instead of relying on request parameters
    private function doInspect(Project $project)
    {
        try {
            $request = $this->client->get('/repos/'.$project->getGithubFullName());
            $response = $request->send();
        } catch (\Guzzle\Http\Exception\ClientErrorResponseException $e) {
            return false;
        }

        $infos = $response->json();

        # @todo @slug
        $project->setSlug(preg_replace('/[^a-z0-9\-]/', '-', strtolower($infos['full_name'])));

        $project->setGithubId($infos['id']);
        $project->setGithubOwnerLogin($infos['owner']['login']);
        $project->setGithubFullName($infos['full_name']);
        $project->setGithubUrl($infos['url']);
        $project->setName($infos['name']);
        $project->setCloneUrl($infos['clone_url']);
        $project->setSshUrl($infos['ssh_url']);
        $project->setKeysUrl($infos['keys_url']);
        $project->setHooksUrl($infos['hooks_url']);
        $project->setContentsUrl($infos['contents_url']);
        $project->setDockerBaseImage('symfony2:latest');
        $project->setGithubPrivate($infos['private']);

        if (false && isset($infos['organization'])) {
            $this->logger->info('attaching project\'s organization', ['organization' => $infos['organization']['login']]);
    
            $rp = $this->doctrine->getRepository('Model:Organization');

            if (null === $org = $rp->findOneByName($infos['organization']['login'])) {
                $this->logger->info('organization not found, creating', ['organization' => $infos['organization']['login']]);
                $orgKeys = $this->sshKeysGenerator->generate();

                $org = new Organization();
                $org->setName($infos['organization']['login']);
                $org->setGithubId($infos['organization']['id']);
                $org->setPublicKey($orgKeys['public']);
                $org->setPrivateKey($orgKeys['private']);
            }

            $project->setOrganization($org);
        } else {
            $this->logger->info('project has no organization, skipping');
        }

        # @todo does this really belong here?
        if (null !== $this->getUser()) {
            $project->addUser($this->getUser());
        }
    }

    private function doKeys(Project $project)
    {
        $keys = $this->sshKeysGenerator->generate();

        $project->setPublicKey($keys['public']);
        $project->setPrivateKey($keys['private']);
    }

    private function doDeployKey(Project $project)
    {
        if (!$project->getGithubPrivate()) {
            return;
        }

        $request = $this->client->get($project->getKeysUrl());
        $response = $request->send();

        $keys = $response->json();
        $projectDeployKey = $project->getPublicKey();

        $scheduleDelete = [];

        foreach ($keys as $key) {
            if ($key['key'] === $projectDeployKey) {
                $installedKey = $key;
                continue;
            }

            if (strpos($key['title'], 'stage1.io') === 0) {
                $scheduleDelete[] = $key;
            }
        }

        if (!isset($installedKey)) {
            $request = $this->client->post($project->getKeysUrl());
            $request->setBody(json_encode([
                'key' => $projectDeployKey,
                'title' => 'stage1.io (added by '.$this->getUser()->getUsername().')',
            ]), 'application/json');

            $response = $request->send();
            $installedKey = $response->json();
        }

        $project->setGithubDeployKeyId($installedKey['id']);

        if ($this->delete_old_keys && count($scheduleDelete) > 0) {
            foreach ($scheduleDelete as $key) {
                $request = $this->client->delete([$project->getKeysUrl(), ['key_id' => $key['id']]]);
                $response = $request->send();
            }
        }
    }

    private function doWebhook(Project $project)
    {
        $user = $this->getUser();

        if (!($user->hasAccessTokenScope('public_repo') || $user->hasAccessTokenScope('repo'))) {
            return;
        }

        $githubHookUrl = $this->generateUrl('app_core_hooks_github', [], true);

        # @todo the fuck is this?
        # ok I get it, it must be when generating hooks url from the dev VM, we get
        # an URL pointing to localhost but we really want one pointing to stage1.io
        $githubHookUrl = str_replace('http://localhost', 'http://stage1.io', $githubHookUrl);

        $request = $this->client->get($project->getHooksUrl());
        $response = $request->send();

        $hooks = $response->json();

        foreach ($hooks as $hook) {
            if ($hook['name'] === 'web' && $hook['config']['url'] === $githubHookUrl) {
                $installedHook = $hook;
                break;
            }
        }

        if (!isset($installedHook)) {
            $request = $this->client->post($project->getHooksUrl());
            $request->setBody(json_encode([
                'name' => 'web',
                'active' => true,
                'events' => ['push', 'pull_request'],
                'config' => ['url' => $githubHookUrl, 'content_type' => 'json'],
            ]), 'application/json');

            $response = $request->send();
            $installedHook = $response->json();
        }

        $project->setGithubHookId($installedHook['id']);
    }

    private function doBranches(Project $project)
    {
        $request = $this->client->get(['/repos/{owner}/{repo}/branches', [
            'owner' => $project->getGithubOwnerLogin(),
            'repo' => $project->getName(),
        ]]);

        $response = $request->send();

        foreach ($response->json() as $data) {
            $branch = new Branch();
            $branch->setName($data['name']);

            $branch->setProject($project);
            $project->addBranch($branch);
        }
    }

    private function doPullRequests(Project $project)
    {
        $request = $this->client->get(['/repos/{owner}/{repo}/pulls', [
            'owner' => $project->getGithubOwnerLogin(),
            'repo' => $project->getName(),
        ]]);

        $response = $request->send();

        foreach ($response->json() as $data) {
            $pr = new PullRequest();
            $pr->setNumber($data['number']);
            $pr->setOpen($data['state'] === 'open');
            $pr->setTitle($data['title']);
            $pr->setRef(sprintf('pull/%d/head', $data['number']));

            $pr->setProject($project);
            $project->addPullRequest($pr);
        }
    }

    private function doAccess(Project $project)
    {
        if (null === $this->initialProjectAccess) {
            # no initial project access means the project
            # is public (most likely, a demo project)
            return;
        }

        if (!$project->getGithubPrivate()) {
            # public projects don't have access management
            return;
        }

        # this is one special ip that cannot be revoked
        # it is used to keep the access list "existing"
        # thus activating auth on the staging areas
        # yes, it's a bit hacky.
        $this->grantProjectAccess($project, new ProjectAccess('0.0.0.0'));

        # this, however, is perfectly legit.
        $this->grantProjectAccess($project, $this->initialProjectAccess);

        # @todo @channel_auth move channel auth to an authenticator service
        # @todo @obsolete actually this might not be necessary because we don't
        #                 directly use the project's channel
        // $this->projectAccessToken = uniqid(mt_rand(), true);
        // $this->redis->sadd('channel:auth:' . $project->getChannel(), $this->projectAccessToken);
    }
}