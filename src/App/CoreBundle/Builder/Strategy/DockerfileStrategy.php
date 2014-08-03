<?php

namespace App\CoreBundle\Builder\Strategy;

use App\CoreBundle\Message\BuildMessage;
use App\Model\Build;
use App\Model\BuildScript;
use Docker\Docker;
use Docker\Context\Context;
use Doctrine\Common\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use OldSound\RabbitMqBundle\RabbitMq\Producer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;
use Redis;

class DockerfileStrategy
{
    private $logger;

    private $docker;

    private $objectManager;

    private $websocketProducer;

    private $options = [];

    public function __construct(LoggerInterface $logger, Docker $docker, ObjectManager $objectManager, Producer $websocketProducer, Redis $redis, array $options)
    {
        $this->logger = $logger;
        $this->docker = $docker;
        $this->objectManager = $objectManager;
        $this->websocketProducer = $websocketProducer;
        $this->redis = $redis;
        $this->options = $options;
    }

    public function getOption($name)
    {
        return $this->options[$name];
    }

    public function getCmd()
    {
        return null;
    }

    public function build(Build $build, BuildScript $script, $timeout)
    {
        $logger = $this->logger;
        $docker = $this->docker;
        $websocketProducer = $this->websocketProducer;
        $redis = $this->redis;

        $publish = function ($content) use ($build, $websocketProducer, $redis) {
            static $fragment = 0;

            $message = new BuildMessage($build, $content);
            $websocketProducer->publish((string) $message);

            $redis->rpush($build->getLogsList(), json_encode([
                'type' => Build::LOG_OUTPUT,
                'message' => $content,
                'stream' => 'stdout',
                'microtime' => microtime(true),
                'fragment_id' => $fragment++,
                'build_id' => $build->getId(),
            ]));
        };

        $project = $build->getProject();
        $options = $build->getOptions();

        $workdir = sys_get_temp_dir().'/stage1/workdir/'.$build->getId();

        if (is_dir($workdir)) {
            $fs = new Filesystem();
            $fs->remove($workdir);
        }

        mkdir($workdir, 0777, true);

        $logger->info('using workdir', ['workdir' => $workdir]);

        mkdir($workdir.'/ssh', 0755, true);

        $project->dumpSshKeys($workdir.'/ssh', 'root');

        file_put_contents($workdir.'/ssh/config', $project->getSshConfig($workdir.'/ssh'));
        file_put_contents($workdir.'/git_ssh', "#!/bin/bash\nexec /usr/bin/ssh -F $workdir/ssh/config \"\$@\"");
        chmod($workdir.'/git_ssh', 0777);

        $GIT_SSH = $workdir.'/git_ssh';

        if ($build->isPullRequest()) {
            $clone = ProcessBuilder::create(['git', 'clone', '--quiet', '--depth', '1', $project->getGitUrl(), $workdir.'/source'])
                ->setEnv('GIT_SSH', $GIT_SSH)
                ->getProcess();

            $commandLine = $clone->getCommandLine();

            $logger->info('cloning repository', ['command_line' => $commandLine]);
            $publish('$ '.substr($commandLine, 0, strrpos($commandLine, ' ')).PHP_EOL);
            $clone->run();

            $fetch = ProcessBuilder::create(['git', 'fetch', '--quiet', 'origin', 'refs/'.$build->getRef()])
                ->setEnv('GIT_SSH', $GIT_SSH)
                ->setWorkingDirectory($workdir.'/source')
                ->getProcess();

            $logger->info('fecthing pull request', ['command_line' => $fetch->getCommandLine()]);
            $publish('$ '.$fetch->getCommandLine().PHP_EOL);
            $fetch->run();

            $checkout = ProcessBuilder::create(['git', 'checkout', '--quiet', '-b', 'pull_request', 'FETCH_HEAD'])
                ->setEnv('GIT_SSH', $GIT_SSH)
                ->setWorkingDirectory($workdir.'/source')
                ->getProcess();

            $logger->info('checkouting pull request', ['command_line' => $checkout->getCommandLine()]);
            $publish('$ '.$checkout->getCommandLine().PHP_EOL);
            $checkout->run();
        } else {
            $clone = ProcessBuilder::create(['git', 'clone', '--quiet', '--depth', '1', '--branch', $build->getRef(), $project->getGitUrl(), $workdir.'/source'])
                ->setEnv('GIT_SSH', $GIT_SSH)
                ->getProcess();

            $commandLine = $clone->getCommandLine();

            $logger->info('cloning repository', ['command_line' => $commandLine]);
            $publish('$ '.substr($commandLine, 0, strrpos($commandLine, ' ')).PHP_EOL);
            $clone->run();
        }

        $contextPath = $workdir.'/source/'.$options['dockerfile']['path'];

        $logger->info('creating docker build context from path', [
            'context_path' => $contextPath,
        ]);

        $context = new Context($contextPath);

        $logger->info('starting actual build', [
            'build' => $build->getId(),
            'timeout' => $timeout,
        ]);

        $response = $docker->build($context, $build->getImageName(), false, false, true, false);

        $error = false;

        $response->read(function($output) use ($logger, $response, $publish, &$error) {
            if ($response->headers->get('content-type') === 'application/json') {
                $output = json_decode($output, true);
                $logger->info('got data chunk', ['output' => $output]);
                $publish($output['stream']);
            } else {
                $message = $output;
            }
        });
    }
}