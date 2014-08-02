<?php

namespace App\CoreBundle\Provider\GitHub;

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

        /** @todo use ProviderInterface#getName */
        if (strlen($user->hasProviderAccessToken('github'))) {
            /** @todo use ProviderInterface#getName */
            $this->setAccessToken($user->getProviderAccessToken('github'));
        }
    }

    public function import($fullName, Closure $callback = null)
    {
        $this->logger->info('importing project', ['full_name' => $fullName]);

        $project = new Project();
        $project->setFullName($fullName);

        if (null === $callback) {
            $callback = function() {};
        }

        $callback(['id' => 'inspect', 'label' => 'Inspecting project']);
        $this->logger->debug('running inspect step', ['project' => $fullName]);

        if (false === $this->doInspect($project)) {
            return false;
        }

        $callback(['id' => 'keys', 'label' => 'Generating keys']);
        $this->logger->debug('running keys step', ['project' => $fullName]);
        $this->doKeys($project);

        $callback(['id' => 'deploy_key', 'label' => 'Adding deploy key']);
        $this->logger->debug('running deploy_key step', ['project' => $fullName]);
        $this->doDeployKey($project);

        $callback(['id' => 'webhook', 'label' => 'Configuring webhook']);
        $this->logger->debug('running webhook step', ['project' => $fullName]);
        $this->doWebhook($project);

        $callback(['id' => 'branches', 'label' => 'Importing branches']);
        $this->logger->debug('running branches step', ['project' => $fullName]);
        $this->doBranches($project);

        $callback(['id' => 'pull_requests', 'label' => 'Importing pull requests']);
        $this->logger->debug('running pull requests step', ['project' => $fullName]);
        $this->doPullRequests($project);

        $callback(['id' => 'access', 'label' => 'Granting default access']);
        $this->logger->debug('running access step', ['project' => $fullName]);
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
            $request = $this->client->get('/repos/'.$project->getFullName());
            $response = $request->send();
        } catch (\Guzzle\Http\Exception\ClientErrorResponseException $e) {
            $this->logger->error($e->getMessage());
            return false;
        }

        $infos = $response->json();

        # @todo @slug
        $project->setSlug(preg_replace('/[^a-z0-9\-]/', '-', strtolower($infos['full_name'])));

        $providerData = [
            'id' => $infos['id'],
            'owner_login' => $infos['owner']['login'],
            'full_name' => $infos['full_name'],
            'url' => $infos['url'],
            'clone_url' => $infos['clone_url'],
            'ssh_url' => $infos['ssh_url'],
            'keys_url' => $infos['keys_url'],
            'hooks_url' => $infos['hooks_url'],
            'contents_url' => $infos['contents_url'],
            'private' => $infos['private'],
        ];

        /** @todo this is to be set with ProviderInterface#getName */
        $project->setProviderName('github');

        $project->setProviderData($providerData);
        $project->setFullName($infos['full_name']);
        $project->setName($infos['name']);
        $project->setDockerBaseImage('symfony2:latest');
        $project->setIsPrivate($infos['private']);

        if (isset($infos['organization'])) {
            $this->logger->info('attaching project\'s organization', ['organization' => $infos['organization']['login']]);
    
            $rp = $this->doctrine->getRepository('Model:Organization');

            if (null === $org = $rp->findOneByName($infos['organization']['login'])) {
                $this->logger->info('organization not found, creating', ['organization' => $infos['organization']['login']]);
                $orgKeys = $this->sshKeysGenerator->generate();

                $org = new Organization();
                $org->setName($infos['organization']['login']);
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

    /** @todo refactor this to use the provider's installDeployKeys method */
    private function doDeployKey(Project $project)
    {
        if (!$project->getIsPrivate()) {
            return;
        }

        $keysUrl = $project->getProviderData('keys_url');

        $request = $this->client->get($keysUrl);
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
            $request = $this->client->post($keysUrl);
            $request->setBody(json_encode([
                'key' => $projectDeployKey,
                'title' => 'stage1.io (added by '.$this->getUser()->getUsername().')',
            ]), 'application/json');

            $response = $request->send();
            $installedKey = $response->json();
        }

        $project->setProviderData('deploy_key_id', $installedKey['id']);

        if ($this->delete_old_keys && count($scheduleDelete) > 0) {
            foreach ($scheduleDelete as $key) {
                $request = $this->client->delete([$keysUrl, ['key_id' => $key['id']]]);
                $response = $request->send();
            }
        }
    }

    /** @todo refactor this to use the provider's installWebHooks method */
    private function doWebhook(Project $project)
    {
        $user = $this->getUser();

        $neededScope = $project->getIsPrivate() ? 'repo' : 'public_repo';

        /** @todo use ProviderInterface#getName */
        if (!$user->hasProviderScope('github', $neededScope)) {
            return;
        }

        $githubHookUrl = $this->generateUrl('app_core_hooks_github', [], true);

        # @todo the fuck is this?
        # ok I get it, it must be when generating hooks url from the dev VM, we get
        # an URL pointing to localhost but we really want one pointing to stage1.io
        $githubHookUrl = str_replace('http://localhost', 'http://stage1.io', $githubHookUrl);

        $hooksUrl = $project->getProviderData('hooks_url');

        $request = $this->client->get($hooksUrl);
        $response = $request->send();

        $hooks = $response->json();

        foreach ($hooks as $hook) {
            if ($hook['name'] === 'web' && $hook['config']['url'] === $githubHookUrl) {
                $installedHook = $hook;
                break;
            }
        }

        if (!isset($installedHook)) {
            $request = $this->client->post($hooksUrl);
            $request->setBody(json_encode([
                'name' => 'web',
                'active' => true,
                'events' => ['push', 'pull_request'],
                'config' => ['url' => $githubHookUrl, 'content_type' => 'json'],
            ]), 'application/json');

            $response = $request->send();
            $installedHook = $response->json();
        }

        $project->setProviderData('hook_id', $installedHook['id']);
    }

    /** @todo use the provider */
    private function doBranches(Project $project)
    {
        $url = sprintf('/repos/%s/branches', $project->getFullName());

        $request = $this->client->get($url);
        $response = $request->send();

        foreach ($response->json() as $data) {
            $branch = new Branch();
            $branch->setName($data['name']);

            $branch->setProject($project);
            $project->addBranch($branch);
        }
    }

    /** @todo use the provider */
    private function doPullRequests(Project $project)
    {
        $url = sprintf('/repos/%s/pulls', $project->getFullName());

        $request = $this->client->get($url);
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

        if (!$project->getIsPrivate()) {
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
    }
}