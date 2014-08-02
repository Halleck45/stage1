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
            'Env' => [
                'BUILD_ID='.$build->getId(),
                'PROJECT_ID='.$build->getProject()->getId(),
                'CHANNEL='.$build->getChannel(),
                'SSH_URL='.$build->getProject()->getGitUrl(),
                'REF='.$build->getRef(),
                'HASH='.$build->getHash(),
                'IS_PULL_REQUEST='.($build->isPullRequest() ? 1 : 0),
                /**
                 * @todo there must be a way to avoid requiring a valid access token
                 *       I think the token is only used to avoid hitting github's
                 *       API limit through composer, so maybe there's a way to use a
                 *       stage1 specific token instead
                 *
                 * @todo refactoring github (->getAccessToken() is deprecated)
                 */
                'ACCESS_TOKEN='.$build->getProject()->getUsers()->first()->getAccessToken(),
                'SYMFONY_ENV=prod',
                // @todo one idea to override parameters could be to read the parameters.yml
                //       modify the parameters array and re-dump it to yaml
                //       this technique should also detect the presence of the incenteev/ParameterHandler
                //       to populate/override an env-map in the composer.json
                // 'STAGE1__DATABASE_HOST=127.0.0.1',
                // 'STAGE1__DATABASE_PORT=~',
                // 'STAGE1__DATABASE_NAME=symfony',
                // 'STAGE1__DATABASE_USER=root',
                // 'STAGE1__DATABASE_PASSWORD=~',
            ],
            'Image' => $build->getImageName(),
            'Cmd' => ['buildapp'],
            'Volumes' => ['/.composer/cache' => []]
        ]);
    }
}