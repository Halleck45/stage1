<?php

namespace App\CoreBundle\Builder\Strategy;

use App\CoreBundle\Docker\BuildContainer;
use App\CoreBundle\Message\BuildMessage;
use App\Model\Build;
use App\Model\BuildScript;
use Docker\Docker;
use Doctrine\Common\Persistence\ObjectManager;
use OldSound\RabbitMqBundle\RabbitMq\Producer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Exception;

class DefaultStrategy
{
    private $logger;

    private $docker;

    private $objectManager;

    private $websocketProducer;

    private $options = [];

    public function __construct(LoggerInterface $logger, Docker $docker, ObjectManager $objectManager, Producer $websocketProducer, array $options)
    {
        $this->logger = $logger;
        $this->docker = $docker;
        $this->objectManager = $objectManager;
        $this->websocketProducer = $websocketProducer;
        $this->options = $options;
    }

    /**
     * @param string $name
     */
    public function getOption($name)
    {
        return $this->options[$name];
    }

    public function getCmd()
    {
        return ['runapp'];
    }

    public function build(Build $build, BuildScript $script, $timeout)
    {
        $logger = $this->logger;
        $docker = $this->docker;
        $objectManager = $this->objectManager;
        $websocketProducer = $this->websocketProducer;

        $publish = function ($content) use ($build, $websocketProducer) {
            $message = new BuildMessage($build, $content);
            $websocketProducer->publish((string) $message);
        };

        $options = $build->getOptions();
        $project = $build->getProject();

        /**
         * Launch actual build
         */
        $logger->info('building base build container', [
            'build' => $build->getId(),
            'image_name' => $build->getImageName()
        ]);
        // $publish('  building base container'.PHP_EOL);

        $baseImage = strpos($options['image'], 'stage1/') !== 0
            ? 'stage1/'.$options['image']
            : $options['image'];

        $builder = $project->getDockerContextBuilder();
        $builder->add('/usr/local/bin/yuhao_build', $script->getBuildScript());
        $builder->add('/usr/local/bin/yuhao_run', $script->getRunScript());
        $builder->run('chmod -R +x /usr/local/bin/');
        $builder->from($baseImage);

        $response = $docker->build($builder->getContext(), $build->getImageName(), false, true, true);

        $buildContainer = new BuildContainer($build);
        $buildContainer->addEnv($options['env']);

        $script->setRuntimeEnv($buildContainer->getEnv());

        if ($build->getForceLocalBuildYml()) {
            $buildContainer->addEnv(['FORCE_LOCAL_STAGE1_YML=1']);
        }

        $manager = $docker->getContainerManager();

        $logger->info('starting actual build', [
            'build' => $build->getId(),
            'timeout' => $timeout,
        ]);

        // $publish('  starting actual build'.PHP_EOL);

        $hostConfig = [];

        if ($this->getOption('composer_enable_global_cache')) {
            $logger->info('enabling composer global cache', ['build' => $build->getId()]);
            $hostConfig['Binds'] = [$this->getOption('composer_cache_path').'/global:/.composer/cache'];
        } elseif ($this->getOption('composer_enable_project_cache')) {
            $cachePath = $this->getOption('composer_cache_path').'/'.$project->getFullName();
            $logger->info('enabling composer project cache', ['build' => $build->getId(), 'project' => $project->getFullName(), 'cache_path' => $cachePath]);

            if (!is_dir($cachePath)) {
                mkdir($cachePath, 0777, true);
            }

            $hostConfig['Binds'] = [realpath($cachePath).':/.composer/cache'];
        }

        $manager->create($buildContainer);

        $build->setContainer($buildContainer);
        
        $manager->start($buildContainer, $hostConfig);
        $manager->wait($buildContainer, $timeout);

        if ($buildContainer->getExitCode() !== 0) {
            $exitCode = $buildContainer->getExitCode();
            $exitCodeLabel = isset(Process::$exitCodes[$exitCode]) ? Process::$exitCodes[$exitCode] : '';

            $message = sprintf('build container stopped with exit code %d (%s)', $exitCode, $exitCodeLabel);

            $logger->error($message, [
                'build' => $build->getId(),
                'container' => $buildContainer->getId(),
                'container_name' => $buildContainer->getName(),
                'exit_code' => $exitCode,
                'exit_code_label' => $exitCodeLabel,
            ]);

            $docker->commit($buildContainer, [
                'repo' => $build->getImageName(),
                'tag' => 'failed'
            ]);

            throw new Exception($message, $buildContainer->getExitCode());
        }

        /**
         * Build successful!
         * 
         * @todo the commit can timeout for no obvious reason, while actually committing
         *       catch the timeout and check if the image has been committed
         *          if yes, proceed
         *          if not, retry (3 times ?)
         */
        $logger->info('build successful, committing', ['build' => $build->getId(), 'container' => $buildContainer->getId()]);
        // $publish('  committing app container'.PHP_EOL);
        $docker->commit($buildContainer, ['repo' => $build->getImageName()]);

        $logger->info('removing build container', ['build' => $build->getId(), 'container' => $buildContainer->getId()]);
        // $publish('  removing build container'.PHP_EOL);
        $manager->remove($buildContainer);
    }
}
