<?php

namespace App\CoreBundle\Controller;

use App\Model\Build;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class DefaultController extends Controller
{
    public function indexAction()
    {
        if ($this->get('security.context')->isGranted('ROLE_USER')) {
            return $this->dashboardAction();
        }

        return $this->render('AppCoreBundle:Default:index.html.twig');
    }

    public function dashboardAction()
    {
        $runningBuilds = $this->get('doctrine')
            ->getRepository('Model:Build')
            ->findRunningBuildsByUser($this->getUser());

        return $this->render('AppCoreBundle:Default:dashboard.html.twig', [
            'builds' => $runningBuilds,
        ]);
    }

    /** @deprecated */
    public function projectsImportAction(Request $request)
    {
        $existingProjects = [];

        foreach ($this->getUser()->getProjects() as $project) {
            $existingProjects[$project->getFullName()] = $this->generateUrl('app_core_project_show', ['id' => $project->getId()]);
        }

        $session = $this->get('session');

        $autostart = $request->get('autostart');

        // if (null === $request->get('autostart') || $request->get('autostart')) {
        //     $autostart = $session->get('projects_import/autostart', $request->get('autostart'));
        // } else {
        //     $autostart = null;
        // }

        $session->remove('projects_import/autostart');

        return $this->render('AppCoreBundle:Default:projectsImport.html.twig', [
            'access_token' => $this->getUser()->getAccessToken(),
            'existing_projects' => $existingProjects,
            'github_api_base_url' => $this->container->getParameter('github_api_base_url'),
            'autostart' => $autostart,
        ]);
    }

    /** @deprecated */
    public function projectImportAction(Request $request)
    {
        $user = $this->getUser();
        $session = $this->get('session');
        $infos = $request->request->all();

        // $client = $this->get('app_core.client.github');
        // $client->setDefaultOption('headers/Authorization', 'token '.$user->getAccessToken());

        // $request = $client->get('/repos/'.$infos['github_full_name']);
        // $data = $request->send()->json();

        // $isPrivate = $data['private'];

        if (!$request->get('force') && !($user->hasAccessTokenScope('repo') || $user->hasAccessTokenScope('public_repo'))) {
            $session->set('projects_import/autostart', $infos['github_id']);

            return new JsonResponse(json_encode([
                'ask_scope' => true,
                'github_id' => $infos['github_id'],
            ]));
        }

        $session->remove('projects_import/autostart');

        $this->get('old_sound_rabbit_mq.project_import_producer')->publish(json_encode([
            'request' => $infos,
            'user_id' => $this->getUser()->getId(),
            'websocket_channel' => $this->getUser()->getChannel(),
            'session_id' => $request->getSession()->getId(),
            'client_ip' => $this->getClientIp(),
        ]));

        return new JsonResponse(json_encode(true));
    }    
}
