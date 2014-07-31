<?php

namespace App\AdminBundle\Controller;

class ProjectController extends Controller
{
    public function triggerAction($id, $hash)
    {
        $project = $this->findProject($id);

        $em = $this->get('doctrine')->getManager();
        $rp = $em->getRepository('Model:Build');

        foreach ($rp->findByHash($hash) as $build) {
            $build->setAllowRebuild(true);
            $em->persist($build);
        }

        $em->flush();

        $provider = $this->get('app_core.provider.factory')->getProvider($project);
        $provider->triggerWebHook($project);

        return $this->redirect($this->generateUrl('app_admin_dashboard'));
    }
}