<?php

namespace App\CoreBundle\Provider\GitHub;

use App\CoreBundle\Provider\PayloadInterface;
use App\CoreBundle\Provider\ProviderInterface;
use App\CoreBundle\Provider\Scope;
use App\Model\Build;
use App\Model\Project;
use App\Model\PullRequest;
use App\Model\User;
use Guzzle\Http\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\Extension\Csrf\CsrfProvider\CsrfProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use InvalidArgumentException;

/**
 * App\CoreBundle\Provider\GitHub\Provider
 */
class Provider implements ProviderInterface
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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CsrfProviderInterface
     */
    private $csrfProvider;

    /**
     * @var string
     */
    private $authorizeUrl = '/login/oauth/authorize';

    /**
     * @var string
     */
    private $accessTokenUrl = '/login/oauth/access_token';

    /**
     * @var string
     */
    private $baseUrl = 'https://github.com';

    /**
     * @var string
     */
    private $baseApiUrl = 'https://api.github.com';

    /**
     * @var string
     */
    private $oauthClientId;

    /**
     * @var string
     */
    private $oauthClientSecret;

    /**
     * @var string[]
     */
    private $scopeMap = [
        Scope::SCOPE_PRIVATE => 'repo',
        Scope::SCOPE_PUBLIC => 'public_repo',
        Scope::SCOPE_ACCESS => null
    ];

    /**
     * @param LoggerInterface       $logger
     * @param Client                $client
     * @param UrlGeneratorInterface $router
     * @param CsrfProviderInterface $csrfProvider
     */
    public function __construct(LoggerInterface $logger, Client $client, Discover $discover, Import $import, UrlGeneratorInterface $router, CsrfProviderInterface $csrfProvider, $oauthClientId, $oauthClientSecret)
    {
        $client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');

        $this->logger = $logger;
        $this->client = $client;
        $this->discover = $discover;
        $this->import = $import;
        $this->router = $router;
        $this->csrfProvider = $csrfProvider;
        $this->oauthClientId = $oauthClientId;
        $this->oauthClientSecret = $oauthClientSecret;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'github';
    }

    /**
     * @return string
     */
    public function getDisplayName()
    {
        return 'GitHub';
    }

    /**
     * @return string
     */
    public function getOAuthClientId()
    {
        return $this->oauthClientId;
    }

    /**
     * @return string
     */
    public function getOAuthClientSecret()
    {
        return $this->oauthClientSecret;
    }

    /**
     * @return string
     */
    public function getAuthorizeUrl()
    {
        return $this->baseUrl.$this->authorizeUrl;
    }

    /**
     * @return string
     */
    public function getAccessTokenUrl()
    {
        return $this->baseUrl.$this->accessTokenUrl;
    }

    /**
     * @return string
     */
    public function getAccessToken(Project $project)
    {
        return $project->getUsers()->first()->getProviderAccessToken($this->getName());
    }

    /**
     * Translates a Stage1 scope to the provider scope.
     * 
     * @param string $scope
     * 
     * @return string|null
     */
    public function translateScope($scope)
    {
        if (array_key_exists($scope, $this->scopeMap)) {
            return $this->scopeMap[$scope];
        }

        throw new InvalidArgumentException('Unknown internal scope "'.$scope.'"');
    }

    /**
     * Translates a provider scope to a Stage1 scope
     * @param string $scope
     * 
     * @return string
     */
    public function reverseTranslateScope($scope)
    {
        if (in_array($scope, $this->scopeMap)) {
            return $this->scopeMap[array_search($scope, $this->scopeMap)];
        }

        throw new InvalidArgumentException('Unknown provider scope "'.$scope.'"');
    }

    /**
     * @param Request $request
     * @param User    $user
     */
    public function handleOAuthCallback(Request $request, User $user)
    {
        $code = $request->get('code');
        $token = $request->get('state');

        if (!$this->csrfProvider->isCsrfTokenValid($this->getName(), $token)) {
            throw new Exception('CSRF Mismatch');
        }

        $payload = [
            'client_id' => $this->getOAuthClientId(),
            'client_secret' => $this->getOAuthClientSecret(),
            'code' => $code,
        ];

        $client = clone $this->client;
        $client->setDefaultOption('headers/Accept', 'application/json');

        $request = $client->post($this->getAccessTokenUrl());
        $request->setBody(http_build_query($payload));

        $response = $request->send();
        $data = $response->json();

        if (array_key_exists('error', $data)) {
            $this->logger->error('An error occurred during authentication', ['data' => $data]);

            throw new Exception(sprintf('%s: %s', $data['error'], $data['error_description']));
        }

        $user->setProviderAccessToken($this->getName(), $data['access_token']);
        $user->setProviderScopes($this->getName(), explode(',', $data['scope']));
    }

    /**
     * @param User  $user
     * @param array $scope
     * 
     * @return boolean
     */
    public function hasScope(User $user, $scope)
    {
        $translatedScope = $this->translateScope($scope);

        return (null === $translatedScope)
            ? $user->hasProviderAccessToken($this->getName())
            : $user->hasProviderScope($this->getName(), $translatedScope);
    }

    /**
     * @param User $user
     * @param string $scope
     * @param string $redirectUri
     * 
     * @return Response
     */
    public function requireScope($scope)
    {
        $token = $this->csrfProvider->generateCsrfToken($this->getName());
        $redirectUri = $this->router->generate('app_core_import_oauth_callback', ['providerName' => $this->getName()], true);

        $payload = [
            'client_id' => $this->getOAuthClientId(),
            'redirect_uri' => $redirectUri,
            'state' => $token,
            'scope' => $this->translateScope($scope),
        ];

        $oauthUrl = $this->getAuthorizeUrl().'?'.http_build_query($payload);

        return new RedirectResponse($oauthUrl);
    }

    /**
     * @return Discover
     */
    public function getDiscover()
    {
        return $this->discover;
    }

    /**
     * @return Import
     */
    public function getImporter()
    {
        return $this->import;
    }

    /**
     * @param User $user
     * 
     * @return array
     */
    public function getIndexedRepositories(User $user)
    {
        $indexedProjects = [];

        foreach ($this->getRepositories($user) as $project) {
            if (!array_key_exists($project['owner_login'], $indexedProjects)) {
                $indexedProjects[$project['owner_login']] = [];
            }

            $indexedProjects[$project['owner_login']][] = $project;
        }

        return $indexedProjects;
    }

    /**
     * @param User $user
     * 
     * @return array
     */
    public function getRepositories(User $user)
    {
        return $this->discover->discover($user);
    }

    /**
     * @param Request $request
     * 
     * @return \App\CoreBundle\Provider\PayloadInterface
     */
    public function createPayloadFromRequest(Request $request)
    {
        return new Payload($request);
    }

    /**
     * @param Project           $project
     * @param PayloadInterface  $payload
     * 
     * @todo implement PayloadInterface#getPullRequestUrl and PayloadInterface#getPullRequestTitle
     * 
     * @return PullRequest
     */
    public function createPullRequestFromPayload(Project $project, PayloadInterface $payload)
    {
        $json = $payload->getParsedPayload();

        $pr = new PullRequest();
        $pr->setNumber($payload->getPullRequestNumber());
        $pr->setTitle($json['pull_request']['title']);
        $pr->setRef($payload->getRef());
        $pr->setOpen($payload->isBuildable());
        $pr->setUrl(sprintf('https://github.com/%s/pull/%d', $project->getFullName(), $payload->getPullRequestNumber()));
        $pr->setProject($project);

        return $pr;
    }

    /**
     * @param Build  $build
     * @param string $separator
     * 
     * @return string
     */
    public function getPullRequestHead(Build $build, $separator = ':')
    {
        $project = $build->getProject();

        list($name,) = explode('/', $project->getFullName());

        return $name.$separator.$build->getRef();
    }

    /**
     * @param Project $project
     * 
     * @return Client
     */
    private function configureClientForProject(Project $project)
    {
        return $this->configureClientForUser($project->getUsers()->first());
    }

    /**
     * @param User $user
     * 
     * @return Client
     */
    public function configureClientForUser(User $user)
    {
        $accessToken = $user->getProviderAccessToken($this->getName());

        $client = clone $this->client;
        $client->setDefaultOption('headers/Authorization', 'token '.$accessToken);

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
     * @param string $status
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