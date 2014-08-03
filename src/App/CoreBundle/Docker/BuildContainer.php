<?php

namespace App\CoreBundle\Docker;

use App\Model\Build;
use Docker\Container;

class BuildContainer extends Container
{
    public function __construct(Build $build)
    {
        parent::__construct([
            'Memory' => 256 * 1024 * 1204,  // @todo use configuration, maybe get from project
            'Env' => $this->getEnv($build),
            'Image' => $build->getImageName(),
            'Cmd' => ['buildapp'],
            'Volumes' => ['/.composer/cache' => []]
        ]);
    }

    private function getEnv(Build $build)
    {
        $env = [
            'BUILD_ID='.$build->getId(),
            'PROJECT_ID='.$build->getProject()->getId(),
            'CHANNEL='.$build->getChannel(),
            'SSH_URL='.$build->getProject()->getGitUrl(),
            'REF='.$build->getRef(),
            'HASH='.$build->getHash(),
            'IS_PULL_REQUEST='.($build->isPullRequest() ? 1 : 0),
            'SYMFONY_ENV=prod',
        ];

        $user = $build->getProject()->getUsers()->first();

        if ($user->hasProviderAccessToken('github')) {
            /**
             * @todo there must be a way to avoid requiring a valid access token
             *       I think the token is only used to avoid hitting github's
             *       API limit through composer, so maybe there's a way to use a
             *       stage1 specific token instead
             */
            $env[] = 'GITHUB_ACCESS_TOKEN='.$user->getProviderAccessToken('github');
        }

        return $env;
    }
}