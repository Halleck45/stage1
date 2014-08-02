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
}
