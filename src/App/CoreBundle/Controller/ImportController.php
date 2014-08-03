<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Provider\Exception as ProviderException;
use App\CoreBundle\Provider\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ImportController extends Controller
{
    /**
     * @return Response
     */
    public function indexAction()
    {
        return $this->render('AppCoreBundle:Import:index.html.twig', [
            'providers' => $this->container->getParameter('providers'),
        ]);
    }

    /**
     * @param Request   $request
     * @param string    $providerName
     * 
     * @return Response
     */
    public function importAction(Request $request, $providerName)
    {
        $user = $this->getUser();
        $infos = $request->request->all();
        $session = $request->getSession();

        if (!$request->get('force')) {
            $scope = $infos['private'] ? Scope::SCOPE_PRIVATE : Scope::SCOPE_PUBLIC;
            $provider = $this->get('app_core.provider.factory')->getProviderByName($providerName);

            if (!$provider->hasScope($user, $scope)) {
                $session->set('import/autostart', $infos['slug']);

                return new JsonResponse(json_encode(['ask_scope' => $scope, 'autostart' => $infos['slug']]));
            }
        }

        $session->remove('import/autostart');

        $this->get('old_sound_rabbit_mq.project_import_producer')->publish(json_encode([
            'user_id' => $user->getId(),
            'request' => $infos,
            'provider_name' => $providerName,
            'websocket_channel' => $user->getChannel(),

            // client_ip and session_ip are needed for the initial ProjectAccess
            // (this will be refactored one day)
            'client_ip' => $this->getClientIp(),
            'session_id' => $session->getId(),
        ]));

        return new JsonResponse(json_encode(true));
    }

    /**
     * @param Request   $request
     * @param string    $providerName
     * @param string    $scope
     * 
     * @return Response
     */
    public function scopeAction(Request $request, $providerName, $scope)
    {
        $provider = $this->get('app_core.provider.factory')->getProviderByName($providerName);

        $session = $request->getSession();
        $session->remove('import/redirect_uri');

        if (null !== $autostart = $request->get('autostart')) {
            $redirectUri = $this->generateUrl('app_core_import_provider', [
                'providerName' => $providerName,
                'autostart' => $autostart
            ]);

            $session->set('import/redirect_uri', $redirectUri);
        }

        return $provider->requireScope($scope);
    }

    /**
     * @param Request   $request
     * @param string    $providerName
     * 
     * @return Response
     */
    public function providerAction(Request $request, $providerName)
    {
        $provider = $this->get('app_core.provider.factory')->getProviderByName($providerName);

        if (!$provider->hasScope($this->getUser(), Scope::SCOPE_ACCESS)) {
            $ret = $provider->requireScope(Scope::SCOPE_ACCESS);

            if ($ret instanceof Response) {
                return $ret;
            }
        }

        $indexedProjects = $provider->getIndexedRepositories($this->getUser());

        // looking for already imported projects
        $existingProjects = [];

        foreach ($this->getUser()->getProjectsByProvider($provider) as $project) {
            $existingProjects[$project->getFullName()] = true;
        }

        // looking for joinable projects
        $fullNames = [];

        foreach ($indexedProjects as $org => $projects) {
            foreach ($projects as $project) {
                $fullNames[] = $project['full_name'];
            }
        }

        $joinableProjects = [];
        $projects = $this->get('doctrine')->getRepository('Model:Project')->findByFullName($fullNames);

        foreach ($projects as $project) {
            $joinableProjects[$project->getFullName()] = [
                'users' => $project->getUsers()->map(function($user) { return $user->getUsername(); })->toArray(),
                'url' => $this->generateUrl('app_core_project_show', ['id' => $project->getId()]),
                'join_url' => $this->generateUrl('app_core_project_join', ['id' => $project->getId()]),
            ];
        }

        return $this->render('AppCoreBundle:Import:provider.html.twig', [
            'provider' => $provider,
            'indexedProjects' => $indexedProjects,
            'existingProjects' => $existingProjects,
            'joinableProjects' => $joinableProjects,
            'importUrl' => $this->generateUrl('app_core_import_import', ['providerName' => $providerName]),
            'autostart' => $request->get('autostart')
        ]);            
    }

    /**
     * @param Request   $request
     * @param string    $providerName
     * 
     * @return Response
     */
    public function oauthCallbackAction(Request $request, $providerName)
    {
        $provider = $this->get('app_core.provider.factory')->getProviderByName($providerName);

        try {
            $provider->handleOAuthCallback($request, $this->getUser());

            $manager = $this->get('doctrine.orm.entity_manager');
            $manager->persist($this->getUser());
            $manager->flush();
        } catch (ProviderException $e) {
            return $this->render('AppCoreBundle:Import:index.html.twig', [
                'exception' => $e,
                'providers' => $this->container->getParameter('providers'),
            ]);
        }

        $session = $request->getSession();

        if (null !== $redirectUri = $session->get('import/redirect_uri')) {
            $session->remove('import/redirect_uri');

            return $this->redirect($redirectUri);
        }

        return $this->redirect($this->generateUrl('app_core_import_provider', [
            'providerName' => $providerName
        ]));
    }
}