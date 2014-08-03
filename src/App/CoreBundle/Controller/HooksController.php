<?php

namespace App\CoreBundle\Controller;

use App\Model\Branch;
use App\Model\Build;
use App\Model\Project;
use App\Model\ProjectSettings;
use App\CoreBundle\Provider\PayloadInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Exception;

class HooksController extends Controller
{
    public function scheduleAction(Request $request)
    {
        try {
            $project = $this->findProject($request->get('project'), false);
            $ref = $request->get('ref');
            $hash = $this->getHashFromRef($project, $ref);

            $scheduler = $this->get('app_core.build_scheduler');
            $build = $scheduler->schedule($project, $ref, $hash);

            return new JsonResponse([
                'build_url' => $this->generateUrl('app_core_build_show', ['id' => $build->getId()]),
                'build' => $build->asMessage(),
            ], 201);            
        } catch (Exception $e) {
            $this->container->get('logger')->error($e->getMessage());

            if (method_exists($e, 'getResponse')) {
                $this->container->get('logger')->error($e->getResponse()->getBody(true));
            }

            return new JsonResponse(['class' => 'danger', 'message' => $e->getMessage()], 500);
        }
    }

    public function providerAction(Request $request, $providerName)
    {
        try {
            $logger = $this->get('logger');
            $provider = $this->get('app_core.provider.factory')->getProviderByName($providerName);
            $payload = $provider->createPayloadFromRequest($request);

            if ($payload->isDummy()) {
                return JsonResponse(json_encode(true), 200);
            }

            $manager = $this->get('doctrine.orm.entity_manager');
            $project = $manager->getRepository('Model:Project')->findOneByPayload($payload);

            if (null === $project) {
                $logger->error('could not find a project for payload', [
                    'providerName' => $payload->getProviderName(),
                    'repositoryFullName' => $payload->getRepositoryFullName()
                ]);

                throw $this->createNotFoundException('Unknown '.$provider->getName().' project "'.$payload->getRepositoryFullName().'"');
            }

            $logger->info('found project', ['full_name' => $project->getFullName()]);

            if ($project->getStatus() === Project::STATUS_HOLD) {
                $logger->info('project is on hold');
                return new JsonResponse(['class' => 'danger', 'message' => 'Project is on hold']);
            }

            $strategy = $payload->isPullRequest()
                ? 'schedulePullRequest'
                : 'scheduleBranchPush';

            $logger->info('elected strategy', ['strategy' => $strategy]);

            $response = $this->$strategy($payload, $project);

            if ($response instanceof Response) {
                $logger->info('got response, sending', ['status_code' => $response->getStatusCode()]);

                return $response;
            }

            list($ref, $hash) = $response;

            $logger->info('got ref and hash', ['ref' => $ref, 'hash' => $hash]);

            $scheduler = $this->get('app_core.build_scheduler');
            $build = $scheduler->schedule($project, $ref, $hash, $payload);

            $logger->info('scheduled build', ['build' => $build->getId(), 'ref' => $build->getRef()]);

            return new JsonResponse([
                'build_url' => $this->generateUrl('app_core_build_show', ['id' => $build->getId()]),
                'build' => $build->asMessage(),
            ], 201);
        } catch (Exception $e) {
            $logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);

            if (method_exists($e, 'getResponse')) {
                $logger->error($e->getResponse()->getBody(true));
            }

            return new JsonResponse([
                'class' => 'danger',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * @todo the policy check and Payload->isBuildable() could be implemented
     *       as a voting system
     */
    private function schedulePullRequest(PayloadInterface $payload, Project $project)
    {
        $logger = $this->get('logger');

        switch ($project->getSettings()->getPolicy()) {
            case ProjectSettings::POLICY_ALL:
            case ProjectSettings::POLICY_PR:
                $doBuild = true;
                break;
            case ProjectSettings::POLICY_PATTERNS:
            case ProjectSettings::POLICY_NONE:
                $doBuild = false;
                break;
            default:
                $logger->error('could not find a build policy', [
                    'project' => $project->getFullName(),
                    'pull_request_number' => $payload->getPullRequestNumber()
                ]);

                return new JsonResponse([
                    'project' => $project->getFullName(),
                    'class' => 'danger',
                    'policy' => $project->getSettings()->getPolicy(),
                    'message' => 'Could not find a valid build policy'
                ], 400);
        }

        if (!$doBuild) {
            $logger->info('build declined by project policy', ['project' => $project->getId(), 'number' => $payload->getPullRequestNumber()]);

            return new JsonResponse(['class' => 'info', 'message' => 'Build declined by project policy ('.$project->getSettings()->getPolicy().')'], 200);
        }

        if (!$payload->isBuildable()) {
            return new JsonResponse(json_encode([
                'class' => 'danger',
                'message' => 'pull request is not buildable'
            ]), 200);
        }

        $ref = sprintf('pull/%d/head', $payload->getPullRequestNumber());

        $provider = $this->get('app_core.provider.factory')->getProvider($project);
        
        return [$ref, $provider->getHashFromRef($project, $ref)];
    }

    /**
     * @todo the policy check and Payload->isBuildable() could be implemented
     *       as a voting system
     */
    private function scheduleBranchPush(PayloadInterface $payload, Project $project)
    {
        $logger = $this->get('logger');
        $em = $this->get('doctrine')->getManager();

        if (!$payload->hasRef()) {
            return new JsonResponse(json_encode(null), 400);
        }

        $ref = substr($payload->getRef(), 11);
        $hash = $payload->getHash();

        # then, check if ref is configured to be automatically built
        $doBuild = false;

        switch ($project->getSettings()->getPolicy()) {
            case ProjectSettings::POLICY_ALL:
                $doBuild = true;
                break;
            case ProjectSettings::POLICY_NONE:
            case ProjectSettings::POLICY_PR:
                $doBuild = false;
                break;
            case ProjectSettings::POLICY_PATTERNS:
                $patterns = explode(PHP_EOL, $project->getSettings()->getBranchPatterns());

                foreach ($patterns as $pattern) {
                    $regex = strtr($pattern, ['*' => '.*', '?' => '.']);

                    if (preg_match('/'.$regex.'/i', $ref)) {
                        $doBuild = true;
                    }
                }
                break;
            default:
                $logger->error('could not find a build policy', ['project' => $project->getId(), 'ref' => $ref]);
                return new JsonResponse(['class' => 'danger', 'message' => 'Could not find a build policy'], 400);
        }

        if (!$doBuild) {
            $logger->info('build declined by project policy', ['project' => $project->getId(), 'ref' => $ref]);
            return new JsonResponse(['class' => 'info', 'message' => 'Build declined by project policy ('.$project->getSettings()->getPolicy().')'], 200);
        }

        /** @todo this should be in the PayloadInterface as ->isDelete() or something */
        if ($hash === '0000000000000000000000000000000000000000') {
            $branch = $em
                ->getRepository('Model:Branch')
                ->findOneByProjectAndName($project, $ref);

            $branch->setDeleted(true);

            $em->persist($branch);
            $em->flush();

            return new JsonResponse(json_encode(null), 200);
        }

        $sameHashBuilds = $em
            ->getRepository('Model:Build')
            ->findByHash($hash);

        if (count($sameHashBuilds) > 0) {
            $logger->warn('found builds with same hash', ['count' => count($sameHashBuilds)]);
            $allowRebuild = array_reduce($sameHashBuilds, function($result, $b) {
                return $result || $b->getAllowRebuild();
            }, false);
        }

        if (isset($allowRebuild) && !$allowRebuild) {
            $logger->warn('build already scheduled for hash', ['hash' => $hash]);
            return new JsonResponse(['class' => 'danger', 'message' => 'Build already scheduled for hash'], 400);                    
        } else {
            $logger->info('scheduling build for hash', ['hash' => $hash]);
        }

        return [$ref, $hash];
    }
}
