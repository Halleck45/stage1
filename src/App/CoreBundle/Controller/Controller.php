<?php

namespace App\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller as BaseController;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Model\Project;
use App\Model\Build;
use App\CoreBundle\Message\MessageInterface;
use App\CoreBundle\Value\ProjectAccess;

class Controller extends BaseController
{
    const REGEXP_IP = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';

    /**
     * @return string
     */
    protected function getHashFromRef(Project $project, $ref)
    {
        $provider = $this->get('app_core.provider.factory')->getProvider($project);

        return $provider->getHashFromRef($project, $ref);
    }

    /**
     * @param string $type
     * @param string $message
     */
    protected function addFlash($type, $message)
    {
        $this->getRequest()->getSession()->getFlashBag()->add($type, $message);
    }

    /**
     * @todo fix this
     */
    protected function getClientIp()
    {
        $server = $this->getRequest()->server;

        if (preg_match('/'.self::REGEXP_IP.'$/', $server->get('HTTP_X_FORWARDED_FOR'), $matches)) {
            return $matches[0];
        }

        if (null !== $server->get('REMOTE_ADDR')) {
            return $server->get('REMOTE_ADDR');
        }

        return null;
    }

    protected function getProjectAccessList(Project $project)
    {
        $accessList = $this
            ->get('app_core.redis')
            ->smembers('auth:' . $project->getSlug());

        $accessList = array_filter($accessList, function($token) {
            return $token !== '0.0.0.0';
        });

        $ips = array_filter($accessList, function($string) { return preg_match('/^'.self::REGEXP_IP.'$/S', $string); });
        $tokens = array_diff($accessList, $ips);

        return ['ips' => $ips, 'tokens' => $tokens];
    }

    protected function grantProjectAccess(Project $project, ProjectAccess $access)
    {
        $args = ['auth:'.$project->getSlug()];

        if ($this->container->getParameter('feature_ip_access_list') || $access->getIp() === '0.0.0.0') {
            $args[] = $access->getIp();
        }

        if ($this->container->getParameter('feature_token_access_list')) {
            $args[] = $access->getToken();
        }

        $args = array_filter($args, function($arg) { return strlen($arg) > 0; });

        return call_user_func_array([$this->get('app_core.redis'), 'sadd'], $args);
    }

    protected function revokeProjectAccess(Project $project, ProjectAccess $access)
    {
        return $this
            ->get('app_core.redis')
            ->srem('auth:' . $project->getSlug(), $access->getIp(), $access->getToken());
    }

    protected function isProjectAccessGranted(Project $project, ProjectAccess $access)
    {
        $authKey = 'auth:' . $project->getSlug();

        $results = $this
            ->get('app_core.redis')
            ->multi()
            ->sismember($authKey, $access->getIp())
            ->sismember($authKey, $access->getToken())
            ->exec();

        return (false !== array_search(true, $results));
    }

    /**
     * @return Build|null
     */
    protected function findBuild($id, $checkAuth = true)
    {
        $build = $this->getDoctrine()->getRepository('Model:Build')->find($id);

        if (!$build) {
            throw $this->createNotFoundException('Build not found');
        }

        if ($this->get('security.context')->isGranted('ROLE_ADMIN')) {
            return $build;
        }

        if ($checkAuth && !$build->getProject()->getUsers()->contains($this->getUser())) {
            throw new AccessDeniedException();
        }

        return $build;
    }

    /**
     * @todo use BuildRepository#findPendingByProject
     */
    protected function findPendingBuilds(Project $project)
    {
        $qb = $this->getDoctrine()->getRepository('Model:Build')->createQueryBuilder('b');

        $qb
            ->where($qb->expr()->eq('b.project', ':project'))
            ->andWhere($qb->expr()->in('b.status', [Build::STATUS_SCHEDULED, Build::STATUS_BUILDING]))
            ->setParameter(':project', $project->getId());

        return $qb->getQuery()->execute();
    }

    protected function publishWebsocket(MessageInterface $message)
    {
        $producer = $this->get('old_sound_rabbit_mq.websocket_producer');
        $producer->publish((string) $message);
    }

    protected function removeAndFlush($entity)
    {
        $em = $this->getDoctrine()->getManager();
        $em->remove($entity);
        $em->flush();
    }

    protected function persistAndFlush()
    {
        $entities = func_get_args();

        $em = $this->getDoctrine()->getManager();
        array_walk($entities, [$em, 'persist']);
        $em->flush();
    }

    /**
     * @deprecated
     */
    protected function setCurrentProjectId($id)
    {
        $this->get('request')->attributes->set('current_project_id', (integer) $id);
    }

    /**
     * @deprecated
     */
    protected function setCurrentBuildId($id)
    {
        $this->get('request')->attributes->set('current_build_id', (integer) $id);
    }

    protected function setCurrentProject(Project $project)
    {
        $this->get('request')->attributes->set('current_project_id', (integer) $project->getId());
    }

    protected function setCurrentBuild(Build $build)
    {
        $this->get('request')->attributes->set('current_build_id', (integer) $build->getId());
        $this->SetCurrentProject($build->getProject());
    }

    protected function findUserByUsername($username)
    {
        $user = $this->getDoctrine()->getRepository('Model:User')->findOneByUsername($username);

        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }

        return $user;
    }

    protected function findProject($id, $checkAuth = true)
    {
        $project = $this->getDoctrine()->getRepository('Model:Project')->find($id);

        if (!$project) {
            throw $this->createNotFoundException('Project not found');
        }

        if ($this->get('security.context')->isGranted('ROLE_ADMIN')) {
            return $project;
        }

        if ($checkAuth && !$project->getUsers()->contains($this->getUser())) {
            throw new AccessDeniedException();
        }

        return $project;
    }

    protected function findProjectsBySlug($slug)
    {
        $projects = $this->getDoctrine()->getRepository('Model:Project')->findBySlug($slug);

        if (count($projects) === 0) {
            throw $this->createNotFoundException(sprintf('Could not find projects with slug "%s"', $slug));
        }

        return $projects;
    }

    protected function findProjectBySlug($slug)
    {
        $project = $this->getDoctrine()->getRepository('Model:Project')->findOneBySlug($slug);

        if (!$project) {
            throw $this->createNotFoundException(sprintf('Could not find project with slug "%s"', $slug));
        }
        
        return $project;
    }
}
