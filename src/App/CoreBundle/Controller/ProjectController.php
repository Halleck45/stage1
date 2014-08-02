<?php

namespace App\CoreBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\CoreBundle\Value\ProjectAccess;
use App\Model\Project;

class ProjectController extends Controller
{
    private function getAccessForm(Project $project)
    {
        return $this->createForm('project_access', new ProjectAccess(), [
            'action' => $this->generateUrl('app_core_project_access_create', ['id' => $project->getId()]),
            'method' => 'POST',
        ]);
    }

    private function getMasterPasswordForm(Project $project)
    {
        return $this->createForm('project_master_password', $project, [
            'action' => $this->generateUrl('app_core_project_master_password_update', ['id' => $project->getId()]),
            'method' => 'POST',
        ]);
    }

    private function getProjectBaseImageForm(Project $project)
    {
        return $this->createForm('project_base_image', $project, [
            'action' => $this->generateUrl('app_core_project_admin_base_image_update', ['id' => $project->getId()]),
            'method' => 'POST',
        ]);
    }

    public function deleteAction(Request $request, $id)
    {
        $project = $this->findProject($id);

        $session = $this->get('session');
        $flash = $session->getFlashBag();

        if ($request->request->get('name') !== $project->getName()) {
            $flash->add('delete-error', 'Project name validation failed.');
            return $this->redirect($this->generateUrl('app_core_project_admin', ['id' => $id]));
        }

        if ($request->request->get('csrf_token') !== $session->get('csrf_token')) {
            $flash->add('delete-error', 'CSRF validation failed.');
            return $this->redirect($this->generateUrl('app_core_project_admin', ['id' => $id]));
        }

        $this->removeAndFlush($project);

        $flash->add('success', sprintf('Project <strong>%s</strong> has been deleted.', $project->getName()));

        return $this->redirect($this->generateUrl('app_core_homepage'));
    }

    public function showAction($id)
    {
        return $this->redirect($this->generateUrl('app_core_project_branches', ['id' => $id]));
    }

    public function buildsAction($id, $all = false)
    {
        $this->setCurrentProjectId($id);

        $project = $this->findProject($id);

        $qb = $this->getDoctrine()->getRepository('Model:Build')->createQueryBuilder('b');

        $builds = $qb
            ->leftJoin('b.branch', 'br')
            ->where($qb->expr()->eq('b.project', ':project'))
            ->andWhere('br.deleted = 0')
            ->orderBy('b.createdAt', 'DESC')
            ->setParameter(':project', $project->getId())
            ->getQuery()
            ->setMaxResults(100)
            ->execute();

        $running_builds = array_filter($builds, function($build) { return $build->isRunning(); });
        $pending_builds = array_filter($builds, function($build) { return $build->isPending(); });
        $other_builds = array_filter($builds, function($build) { return !($build->isRunning() || $build->isPending()); });

        return $this->render('AppCoreBundle:Project:builds.html.twig', [
            'project' => $project,
            'other_builds' => $other_builds,
            'running_builds' => $running_builds,
            'pending_builds' => $pending_builds,
            'has_builds' => count($builds) > 0,
            'all' => (bool) $all,
        ]);
    }

    public function scheduleBuildAction(Request $request, $id)
    {
        try {
            $project = $this->findProject($id);
            $ref = $request->request->get('ref');
            $hash = $this->getHashFromRef($project, $ref);

            $scheduler = $this->container->get('app_core.build_scheduler');
            $build = $scheduler->schedule($project, $ref, $hash, null);

            return new JsonResponse([
                'build_url' => $this->generateUrl('app_core_build_show', ['id' => $build->getId()]),
                'cancel_url' => $this->generateUrl('app_core_build_cancel', ['id' => $build->getId()]),
                'build' => $build->asMessage(),
            ], 201);
        } catch (Exception $e) {
            $this->container->get('logger')->error($e->getMessage());
            $this->container->get('logger')->error($e->getResponse()->getBody(true));

            return new JsonResponse(['class' => 'danger', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @param integer $id
     */
    public function settingsAction($id)
    {
        $project = $this->findProject($id);
        $this->setCurrentProjectId($id);

        $form = $this->createForm('project_settings', $project->getSettings(), [
            'action' => $this->generateUrl('app_core_project_settings_update', ['id' => $project->getId()]),
            'method' => 'POST',
        ]);

        return $this->render('AppCoreBundle:Project:settings.html.twig', [
            'form' => $form->createView(),
            'project' => $project
        ]);
    }

    /**
     * @param integer $id
     */
    public function settingsUpdateAction(Request $request, $id)
    {
        $project = $this->findProject($id);
        $this->setCurrentProjectId($id);

        $form = $this->createForm('project_settings', $project->getSettings(), [
            'action' => $this->generateUrl('app_core_project_settings_update', ['id' => $project->getId()]),
            'method' => 'POST',
        ]);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $form->getData()->setProject($project);

            $em = $this->get('doctrine')->getManager();
            $em->persist($form->getData());
            $em->flush();

            if ($form->get('save_and_build')->isClicked()) {
                $ref = 'master';

                $hash = $this->getHashFromRef($project, $ref);
                $scheduler = $this->container->get('app_core.build_scheduler');
                $build = $scheduler->schedule($project, $ref, $hash, null, [
                    'force_local_build_yml' => true,
                ]);

                return $this->redirect($this->generateUrl('app_core_build_output', ['id' => $build->getId()]));
            }

            return $this->redirect($this->generateUrl('app_core_project_settings', ['id' => $project->getId()]));
        }

        return $this->render('AppCoreBundle:Project:settings.html.twig', [
            'form' => $form->createView(),
            'project' => $project
        ]);
    }

    /**
     * @param integer $id
     */
    public function adminAction($id)
    {
        $project = $this->findProject($id);
        $this->setCurrentProjectId($id);

        # @todo replace with SessionCsrfProvider
        $token = uniqid(mt_rand(), true);
        $this->get('session')->set('csrf_token', $token);

        return $this->render('AppCoreBundle:Project:admin.html.twig', [
            'base_image_form' => $this->getProjectBaseImageForm($project)->createView(),
            'project' => $project,
            'csrf_token' => $token,
        ]);
    }

    public function updateBaseImageAction(Request $request, $id)
    {
        $project = $this->findProject($id);
        $this->setCurrentProjectId($id);

        $form = $this->getProjectBaseImageForm($project);

        $form->bind($request);

        if ($form->isValid()) {
            $this->persistAndFlush($project);
            return $this->redirect($this->generateUrl('app_core_project_admin', ['id' => $project->getId()]));
        }

        $token = uniqid(mt_rand(), true);
        $this->get('session')->set('csrf_token', $token);

        return $this->render('AppCoreBundle:Project:admin.html.twig', [
            'base_image_form' => $form->createView(),
            'project' => $project,
            'csrf_token' => $token,
        ]);
    }

    /**
     * @param Request $request
     * @param integer $id
     */
    public function updateEnvAction(Request $request, $id)
    {
        $project = $this->findProject($id);
        $this->setCurrentProjectId($id);

        $project->setEnv($request->request->get('project_env'));

        $this->persistAndFlush($project);

        $this->addFlash('success', 'Project environment saved');

        return $this->redirect($this->generateUrl('app_core_project_admin', ['id' => $project->getId()]));
    }

    /**
     * @param Request $request
     * @param integer $id
     */
    public function updateUrlsAction(Request $request, $id)
    {
        $project = $this->findProject($id);
        $this->setCurrentProjectId($id);

        $project->setUrls($request->request->get('project_urls'));

        $this->persistAndFlush($project);

        $this->addFlash('success', 'Project URLs saved');

        return $this->redirect($this->generateUrl('app_core_project_admin', ['id' => $project->getId()]));
    }

    public function branchesAction($id)
    {
        $this->setCurrentProjectId($id);
        $project = $this->findProject($id);

        if ($project->getSettings() && strlen($project->getSettings()->getPolicy()) === 0) {
            $this->get('session')->set('return', $this->generateUrl('app_core_project_branches', ['id' => $project->getId()]));
            
            return $this->redirect($this->generateUrl('app_core_project_settings_policy', ['id' => $project->getId()]));
        }

        $builds = $this
            ->getDoctrine()
            ->getRepository('Model:Build')
            ->findLastByRefs($project);

        foreach ($builds as $build) {     
            foreach ($project->getActiveBranches() as $branch) {
                if ($branch->getName() == $build->getRef()) {
                    $branch->setLastBuild($build);
                }

                foreach ($project->getActivePullRequests() as $pr) {
                    if ($pr->getRef() === $build->getRef()) {
                        $pr->setLastBuild($build);
                    }
                }
            }
        }

        return $this->render('AppCoreBundle:Project:branches.html.twig', [
            'project' => $project,
            'builds_count' => count($builds),
        ]);
    }

    /** @todo github provider refactoring */
    public function joinAction(Request $request, $id)
    {
        $project = $this->getDoctrine()->getRepository('Model:Project')->find($id);

        if (!$project) {
            throw $this->createNotFoundException();
        }

        $provider = $this->get('app_core.provider.factory')->getProvider($project);
        $users = $provider->getCollaborators($project);

        foreach ($users as $user) {
            if ($user['id'] === $this->getUser()->getGithubId()) {
                $project->addUser($this->getUser());
                $this->persistAndFlush($project);

                return new JsonResponse(json_encode([
                    'status' => 'ok',
                    'project_url' => $this->generateUrl('app_core_project_show', ['id' => $project->getId()]),
                    'project_full_name' => $project->getFullName(),
                ]));
            }
        }

        return new JsonResponse(json_encode(['status' => 'nok', 'message' => 'Not authorized']), 401);
    }

    public function accessDeleteAction(Request $request, $id)
    {
        $this->setCurrentProjectId($id);
        $project = $this->findProject($id);

        $data = $request->request->all();
        $flashBag = $request->getSession()->getFlashBag();

        if (!$this->get('form.csrf_provider')->isCsrfTokenValid('access_delete', $data['access_delete']['csrf_token'])) {
            $flashBag->add('error', 'Invalid CSRF token.');
        } else {
            $this->revokeProjectAccess($project, new ProjectAccess($data['access_delete']['delete']));
            $flashBag->add('success', 'Access revoked.');
        }

        return $this->redirect($this->generateUrl('app_core_project_access', ['id' => $project->getId()]));
    }

    public function accessCreateAction(Request $request, $id)
    {
        $this->setCurrentProjectId($id);
        $project = $this->findProject($id);

        $form = $this->getAccessForm($project);
        $form->bind($request);

        if ($form->isValid()) {
            $this->grantProjectAccess($project, $form->getData());
            $request->getSession()->getFlashBag()->add('success', 'Access granted.');

            return $this->redirect($this->generateUrl('app_core_project_access', ['id' => $project->getId()]));
        }

        $accessList = $this->getProjectAccessList($project);

        return $this->render('AppCoreBundle:Project:access.html.twig', [
            'project' => $project,
            'access_list' => $accessList,
            'access_form' => $form->createView(),
            'access_delete_csrf_token' => $this->get('form.csrf_provider')->generateCsrfToken('access_delete'),
            'master_password_form' => $this->getMasterPasswordForm($project)->createView(),
        ]);
    }

    public function masterPasswordUpdateAction(Request $request, $id)
    {
        $this->setCurrentProjectId($id);
        $project = $this->findProject($id);

        $form = $this->getMasterPasswordForm($project);

        $form->bind($request);

        if ($form->isValid()) {
            if ($form->get('delete')->isClicked() || strlen($form->getData()->getMasterPassword()) === 0) {
                $project->setMasterPassword(null);
                $message = 'Master password deleted.';
            } else {
                $project->setMasterPassword(password_hash($project->getMasterPassword(), PASSWORD_BCRYPT));
                $message = 'Master password updated.';
            }

            $this->persistAndFlush($project);
            $request->getSession()->getFlashBag()->add('success', $message);

            return $this->redirect($this->generateUrl('app_core_project_access', ['id' => $project->getId()]));
        }

        $accessList = $this->getProjectAccessList($project);

        return $this->render('AppCoreBundle:Project:access.html.twig', [
            'project' => $project,
            'access_list' => $accessList,
            'access_form' => $this->getAccessForm($project)->createView(),
            'access_delete_csrf_token' => $this->get('form.csrf_provider')->generateCsrfToken('access_delete'),
            'master_password_form' => $form->createView(),
        ]);
    }

    public function accessAction($id)
    {
        $this->setCurrentProjectId($id);

        $project = $this->findProject($id);

        if (!$project->getIsPrivate()) {
            return $this->redirect($this->generateUrl('app_core_project_branches', ['id' => $project->getId()]));
        }

        $accessList = $this->getProjectAccessList($project);

        return $this->render('AppCoreBundle:Project:access.html.twig', [
            'project' => $project,
            'access_list' => $accessList,
            'access_form' => $this->getAccessForm($project)->createView(),
            'access_delete_csrf_token' => $this->get('form.csrf_provider')->generateCsrfToken('access_delete'),
            'master_password_form' => $this->getMasterPasswordForm($project)->createView(),
        ]);
    }

    public function authAction(Request $request, $slug)
    {
        $project = $this->findProjectBySlug($slug);

        $access = new ProjectAccess($this->getClientIp(), $request->getSession()->getId());
        $isAllowed = $this->isProjectAccessGranted($project, $access);

        if (!$isAllowed && $project->getUsers()->contains($this->getUser())) {
            $this->container->get('app_core.redis')->sadd('auth:'.$project->getSlug(), $request->getSession()->getId());
            $isAllowed = true;
        }

        if (!$isAllowed && !$project->hasMasterPassword()) {
            # assume owner don't want existence of this project to leak
            throw $this->createNotFoundException();
        }

        if ($isAllowed) {
            $runningBuilds = $project->getRunningBuilds();
        }

        return $this->render('AppCoreBundle:Project:auth.html.twig', [
            'project' => $project,
            'is_allowed' => $isAllowed,
            'running_builds' => $isAllowed ? $runningBuilds : [],
            'return' => $request->query->get('return'),
        ]);
    }

    public function authorizeAction(Request $request, $slug)
    {
        $projects = $this->findProjectsBySlug($slug);
        $password = $request->request->get('password');

        foreach ($projects as $project) {
            if (!password_verify($password, $project->getMasterPassword())) {

                $this->get('logger')->error('wrong password for auth', [
                    'project' => $project->getSlug(),
                    'master_password_hash' => $project->getMasterPassword(),
                    'presented_password_hash' => password_hash($password, PASSWORD_BCRYPT)
                ]);

                continue;
            }

            $access = new ProjectAccess($this->getClientIp(), $request->getSession()->getId());
            
            $this->grantProjectAccess($project, $access);

            if (strlen($return = $request->request->get('return')) > 0) {
                return $this->redirect('http://' . $return);
            }

            $this->addFlash('success', 'You have been authenticated');

            break;
        }


        return $this->render('AppCoreBundle:Project:auth.html.twig', [
            'project' => $project,
            'return' => $request->request->get('return'),
        ]);
    }
}