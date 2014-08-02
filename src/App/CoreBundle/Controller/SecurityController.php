<?php

namespace App\CoreBundle\Controller;

use App\Model\User;
use App\Model\BetaSignup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use DateTime;
use RuntimeException;

class SecurityController extends Controller
{
    private function isForceEnabled(User $user, SessionInterface $session)
    {
        if (in_array($user->getUsername(), array('ubermuda', 'pocky'))) {
            return true;
        }

        if (null === ($betaKey = $session->get('beta_key'))) {
            return false;
        }

        $em = $this->getDoctrine()->getManager();
        $repo = $em->getRepository('Model:BetaSignup');

        return (null !== $repo->findByBetaKey($betaKey));
    }

    public function primusAuthAction(Request $request)
    {
        try {
            # @todo @channel_auth move channel auth to an authenticator service
            $channel = $request->request->get('channel');
            $token = uniqid(mt_rand(), true);            

            if (strlen($channel) > 0) {
                $repo = $this->getDoctrine()->getRepository('Model:User');
                $authUser = $repo->findByChannel($channel);

                if ($authUser !== $this->getUser()) {
                    return new JsonResponse(null, 403);
                }
            } else {
                if (!$this->getUser()) {
                    throw new \RuntimeException();
                }

                $channel = $this->getUser()->getChannel();
            }

            $this->get('app_core.redis')->sadd('channel:auth:' . $channel, $token);

            return new JsonResponse(json_encode([
                'channel' => $this->getUser()->getChannel(),
                'token' => $token,
            ]));            
        } catch (\Exception $e) {
            return new JsonResponse(json_encode(false), 500);
        }
    }

    /**
     * @return User
     * 
     * @deprecated
     */
    private function registerGithubUser(Request $request, $accessToken, $scope)
    {
        $client = $this->container->get('app_core.client.github');
        $client->setDefaultOption('headers/Authorization', 'token '.$accessToken);
        $client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');

        $githubRequest = $client->get('/user');
        $githubResponse = $githubRequest->send();

        $result = $githubResponse->json();

        if (null === ($user = $this->getDoctrine()->getRepository('Model:User')->findOneByGithubId($result['id']))) {
            $user = User::fromGithubResponse($result);
            $user->setStatus(User::STATUS_WAITING_LIST);

            // @todo generate random websocket channel name
            $keys = $this
                ->get('app_core.ssh_keys_generator')
                ->generate();

            $user->setPublicKey($keys['public']);
            $user->setPrivateKey($keys['private']);
        }

        $user->setAccessTokenScope($scope);

        if (false && strlen($user->getEmail()) === 0) {
            $githubRequest = $client->get('/user/emails');
            $githubResponse = $githubRequest->send();

            $result = $githubResponse->json();

            foreach ($result as $email) {
                if ($email['primary']) {
                    $user->setEmail($email['email']);
                    break;
                }
            }
        }

        if ($user->getStatus() === User::STATUS_WAITING_LIST) {
            if ($this->isForceEnabled($user, $request->getSession())) {
                $user->setStatus(User::STATUS_ENABLED);
            } else {
                $user->setWaitingList($user->getWaitingList() + 1);
                $this->createBetaSignup($user);
            }
        }

        if (null !== $id = $this->get('session')->get('beta_signup')) {
            $betaSignup = $this->get('doctrine')->getRepository('Model:BetaSignup')->find($id);
            $user->setBetaSignup($betaSignup);
        }

        $user->setLastLoginAt(new DateTime());
        $user->setAccessToken($accessToken);
        $user->addRole('ROLE_USER');

        $em = $this->getDoctrine()->getManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createBetaSignup(User $user)
    {
        $em = $this->get('doctrine')->getManager();
        $repo = $em->getRepository('Model:BetaSignup');

        if (null === $beta = $repo->findOneByEmail($user->getEmail())) {
            $beta = new BetaSignup();
            $beta->setEmail($user->getEmail());
            $beta->setTries($user->getWaitingList());
            $beta->setStatus(BetaSignup::STATUS_DEFAULT);

            $em->persist($beta);            
        }
    }

    /** @deprecated */
    public function authorizeAction(Request $request, $scopes = null)
    {
        if (false && $this->container->getParameter('kernel.environment') === 'dev') {
            if (null !== ($user = $this->getDoctrine()->getRepository('Model:User')->findOneByUsername('ubermuda'))) {
                $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                $this->get('security.context')->setToken($token);

                $loginEvent = new InteractiveLoginEvent($request, $token);
                $this->get('event_dispatcher')->dispatch(SecurityEvents::INTERACTIVE_LOGIN, $loginEvent);

                if ($request->getSession()->has('_security.main.target_path')) {
                    $redirectUrl = $request->getSession()->get('_security.main.target_path');
                    $request->getSession()->remove('_security.main.target_path');
                } else {
                    $redirectRoute = (count($user->getProjects()) == 0) ? 'app_core_projects_import' : 'app_core_homepage';
                    $redirectUrl = $this->generateUrl($redirectRoute);
                }

                return $this->redirect($redirectUrl);
            }
        }

        $session = $this->get('session');

        if (null !== $backTo = $request->get('back_to')) {
            $session->set('oauth/back_to', $backTo);
        }

        $token = $this->get('form.csrf_provider')->generateCsrfToken('github');

        $payload = [
            'client_id' => $this->container->getParameter('github_client_id'),
            'redirect_uri' => $this->generateUrl('app_core_auth_github_callback', [], true),
            'state' => $token,
        ];

        if (strlen($scopes) > 0) {
            $payload['scope'] = $scopes;
        }

        return $this->redirect($this->container->getParameter('github_base_url').'/login/oauth/authorize?'.http_build_query($payload));
    }

    /** @deprecated */
    public function callbackAction(Request $request)
    {
        $code = $request->query->get('code');
        $token = $request->query->get('state');

        if (!$this->get('form.csrf_provider')->isCsrfTokenValid('github', $token)) {
            throw new RuntimeException('CSRF Mismatch');
        }

        $payload = [
            'client_id' => $this->container->getParameter('github_client_id'),
            'client_secret' => $this->container->getParameter('github_client_secret'),
            'code' => $code,
        ];

        $client = $this->get('app_core.client.github');
        $client->setDefaultOption('headers/Accept', 'application/json');

        $githubBaseUrl = $this->container->getParameter('github_base_url');
        $accessTokenRequest = $client->post($githubBaseUrl.'/login/oauth/access_token');
        $accessTokenRequest->setBody(http_build_query($payload));

        $accessTokenResponse = $accessTokenRequest->send();
        $data = $accessTokenResponse->json();

        if (isset($data['error'])) {
            $this->addFlash('error', $data['error']);
            $this->get('logger')->error('An error occurred during authentication', [
                'error' => $data['error'],
                'state' => $token
            ]);
            
            return $this->redirect($this->generateUrl('app_core_homepage'));
        }

        $user = $this->registerGithubUser($request, $data['access_token'], $data['scope']);

        if ($user->hasPrivateProjects() && !$user->hasAccessTokenScope('repo')) {
            return $this->redirect($this->generateUrl('app_core_auth_github_authorize', ['scopes' => 'repo']));
        }

        if ($user->getStatus() === User::STATUS_WAITING_LIST) {
            $request->getSession()->set('waiting_list', $user->getWaitingList());
            return $this->redirect($this->generateUrl('app_core_waiting_list'));
        }

        $session = $this->get('session');

        if (null !== $backTo = $session->get('oauth/back_to')) {
            $session->remove('oauth/back_to');
            return $this->redirect($backTo);
        }

        $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
        $this->get('security.context')->setToken($token);

        $loginEvent = new InteractiveLoginEvent($request, $token);
        $this->get('event_dispatcher')->dispatch(SecurityEvents::INTERACTIVE_LOGIN, $loginEvent);

        if ($request->getSession()->has('_security.main.target_path')) {
            $redirectUrl = $request->getSession()->get('_security.main.target_path');
            $request->getSession()->remove('_security.main.target_path');
        } else {
            $redirectRoute = (count($user->getProjects()) == 0) ? 'app_core_projects_import' : 'app_core_homepage';
            $redirectUrl = $this->generateUrl($redirectRoute);
        }

        return $this->redirect($redirectUrl);
    }
}
