<?php

namespace App\Model;

use Docker\Container;
use BadMethodCallException;

class Build implements WebsocketRoutable
{
    const STATUS_SCHEDULED = 1;

    const STATUS_BUILDING = 2;

    const STATUS_RUNNING = 3;

    const STATUS_CANCELED = 4;

    const STATUS_FAILED = 5;

    const STATUS_KILLED = 6;

    const STATUS_DELETED = 7;

    const STATUS_OBSOLETE = 8;

    const STATUS_STOPPED = 9;

    const STATUS_TIMEOUT = 10;

    const STATUS_DUPLICATE = 11;

    const LOG_OUTPUT = 'output';

    const LOG_APPLICATION = 'application';
    
    private $id;

    private $project;

    private $initiator;

    private $status;

    private $ref;

    private $hash;

    private $port;

    private $host;

    private $pid;

    private $container;

    private $containerId;

    private $containerName;

    private $imageId;

    private $message;

    private $exitCode;

    private $exitCodeText;

    private $createdAt;

    private $updatedAt;

    private $branch;

    private $pullRequest;

    private $logs;

    private $channel;

    private $streamOutput = true;

    private $streamSteps = false;

    private $isDemo = false;

    private $duration = 0;

    private $startTime;

    private $endTime;

    private $memoryUsage;

    private $allowRebuild = false;

    private $options = [];

    private $forceLocalBuildYml = false;

    private $builderHost = null;

    private $payload;

    private $commitUrl;

    public function __construct()
    {
        $this->logs = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function __call($method, $args)
    {
        if (defined($const = 'self::STATUS_'.(strtoupper(strpos($method, 'is') === 0 ? substr($method, 2) : $method)))) {
            return $this->getStatus() === constant($const);
        }

        throw new BadMethodCallException(sprintf('Method "%s" does not exist in object "%s"', $method, __CLASS__));
    }

    public function getTitle()
    {
        return $this->isPullRequest()
            ? $this->getPullRequest()->getTitle()
            : $this->getBranch()->getName();
    }

    public function __toString()
    {
        return json_encode($this->asWebsocketMessage());
    }

    public function isDemo()
    {
        return $this->isBranch() && $this->getBranch()->getIsDemo();
    }

    public function isBranch()
    {
        return !$this->isPullRequest();
    }

    public function isPullRequest()
    {
        if ($this->getPayload()) {
            return $this->getPayload()->isPullRequest();
        }

        return substr($this->getRef(), 0, 4) === 'pull';
    }

    public function getRoutingKey()
    {
        return $this->getBuilderHost();
    }

    public function getLogsList()
    {
        return 'build:output:'.$this->getId();
    }

    /**
     * @return array
     */
    public function getOutputLogs()
    {
        return $this->getLogs(self::LOG_OUTPUT);
    }

    /**
     * @return array
     */
    public function getApplicationLogs()
    {
        return $this->getLogs(self::LOG_APPLICATION);
    }

    public function getUsers()
    {
        return $this->getProject()->getUsers();
    }

    public function getChannel()
    {
        if (null !== $this->channel) {
            return $this->channel;
        }

        if (null === $this->getProject())
        {
            return 'build.'.$this->getId();
        }

        return $this->getProject()->getChannel();
    }

    public function setChannel($channel)
    {
        $this->channel = $channel;
    }

    /**
     * @param string $message
     * @param string $type      log|output
     */
    public function appendLog($message, $type, $stream = null)
    {
        $log = new BuildLog();
        $log->setBuild($this);
        $log->setType($type);
        $log->setMessage(trim($message));
        $log->setStream($stream);
        
        return $log;
    }

    public function hasContainer()
    {
        return $this->containerId !== null;
    }

    # @todo @slug move to its own service

    /**
     * @param string $string
     */
    private function normalize($string)
    {
        return preg_replace('/[^a-z0-9\-]/', '-', strtolower($string));
    }

    public function hasForcedDomain()
    {
        return !$this->isPullRequest() && $this->getBranch()->hasForcedDomain();
    }

    public function getDomain()
    {
        return $this->isPullRequest()
            ? $this->getPullRequestDomain()
            : $this->getBranchDomain();
    }

    public function getPullRequestDomain()
    {
        return $this->getPullRequest()->getDomain();
    }

    public function getBranchDomain()
    {
        return $this->getBranch()->getDomain();
    }

    public function getNormRef()
    {
        return $this->normalize($this->getRef());
    }

    public function asMessage()
    {
        return [
            'id' => $this->getId(),
            'ref' => $this->getRef(),
            'normRef' => $this->getNormRef(),
            'hash' => $this->getHash(),
            'status' => $this->getStatus(),
            'status_label' => $this->getStatusLabel(),
            'status_label_class' => $this->getStatusLabelClass(),
            'url' => $this->getUrl(),
            'port' => $this->getPort(),
            'duration' => $this->getDuration(),
            'output_logs_count' => count($this->getOutputLogs()),
            'project' => (null === $this->getProject()
                ? []
                : $this->getProject()->asMessage()),
        ];

    }

    public function getBaseImageName()
    {
        return $this->getProject()->getDockerBaseImage();
    }

    /**
     * @param string $suffix
     */
    public function getImageName($suffix = null)
    {
        $name = sprintf('b/%d/%s/%d', $this->getProject()->getId(), $this->getNormRef(), $this->getId());

        if (null !== $suffix) {
            $name .= '/'.$suffix;
        }

        return $name;
    }

    public function getImageTag()
    {
        return $this->getId();
    }

    public function isPending()
    {
        return in_array($this->getStatus(), [
            self::STATUS_SCHEDULED,
            self::STATUS_BUILDING
        ]);
    }

    public function getStatusLabelClass()
    {
        switch ($this->getStatus()) {
            case self::STATUS_BUILDING:
                return 'info';
            case self::STATUS_RUNNING:
                return 'success';
            case self::STATUS_FAILED:
            case self::STATUS_TIMEOUT:
                return 'important';
            case self::STATUS_KILLED:
            case self::STATUS_DUPLICATE:
                return 'warning';
            default:
                return 'default';
        }
    }

    public function getStatusLabel()
    {
        switch ($this->getStatus()) {
            case self::STATUS_SCHEDULED:
                return 'scheduled';
            case self::STATUS_BUILDING:
                return 'building';
            case self::STATUS_RUNNING:
                return 'running';
            case self::STATUS_FAILED:
                return 'failed';
            case self::STATUS_CANCELED:
                return 'canceled';
            case self::STATUS_KILLED:
                return 'killed';
            case self::STATUS_DELETED:
                return 'deleted';
            case self::STATUS_OBSOLETE:
                return 'obsolete';
            case self::STATUS_STOPPED:
                return 'stopped';
            case self::STATUS_TIMEOUT:
                return 'timeout';
            case self::STATUS_DUPLICATE:
                return 'duplicate';
            default:
                return 'unknown';
        }
    }

    /**
     * Set status
     *
     * @param integer $status
     * @return Build
     */
    public function setStatus($status)
    {
        $this->status = $status;

        if ($status !== self::STATUS_RUNNING) {
            $this->setPort(null);
        }
    
        return $this;
    }

    /**
     * Get status
     *
     * @return integer 
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set ref
     *
     * @param string $ref
     * @return Build
     */
    public function setRef($ref)
    {
        $this->ref = $ref;
    
        return $this;
    }

    /**
     * Get ref
     *
     * @return string 
     */
    public function getRef()
    {
        return $this->ref;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     * @return Build
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    
        return $this;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime 
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set updatedAt
     *
     * @param \DateTime $updatedAt
     * @return Build
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
    
        return $this;
    }

    /**
     * Get updatedAt
     *
     * @return \DateTime 
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * @param integer $id
     * 
     * @return Build
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set project
     *
     * @param \App\Model\Project $project
     * @return Build
     */
    public function setProject(\App\Model\Project $project = null)
    {
        $this->project = $project;
    
        return $this;
    }

    /**
     * Get project
     *
     * @return \App\Model\Project 
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * Set initiator
     *
     * @param \App\Model\User $initiator
     * @return Build
     */
    public function setInitiator(\App\Model\User $initiator = null)
    {
        $this->initiator = $initiator;
    
        return $this;
    }

    /**
     * Get initiator
     *
     * @return \App\Model\User 
     */
    public function getInitiator()
    {
        return $this->initiator;
    }

    /**
     * Set hash
     *
     * @param string $hash
     * @return Build
     */
    public function setHash($hash)
    {
        $this->hash = $hash;
    
        return $this;
    }

    /**
     * Get hash
     *
     * @return string 
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * Set containerId
     *
     * @param string $containerId
     * @return Build
     */
    public function setContainerId($containerId)
    {
        $this->containerId = $containerId;

        if ($this->container && $this->container->getId() !== $containerId) {
            $this->container = null;
        }
    
        return $this;
    }

    /**
     * Get containerId
     *
     * @return string 
     */
    public function getContainerId()
    {
        return $this->containerId;
    }

    /**
     * @return App\Model\Build
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;
        $this->setContainerId($container->getId());
        $this->setContainerName($container->getName());
    }

    /**
     * @return Container|null
     */
    public function getContainer()
    {
        if (null === $this->container && strlen($this->getContainerId()) > 0) {
            $container = new Container();
            $container->setId($this->getContainerId());

            $this->container = $container;
        }

        return $this->container;
    }

    /**
     * Set imageId
     *
     * @param string $imageId
     * @return Build
     */
    public function setImageId($imageId)
    {
        $this->imageId = $imageId;
    
        return $this;
    }

    /**
     * Get imageId
     *
     * @return string 
     */
    public function getImageId()
    {
        return $this->imageId;
    }

    /**
     * Set message
     *
     * @param string $message
     * @return Build
     */
    public function setMessage($message)
    {
        $this->message = $message;
    
        return $this;
    }

    /**
     * Get message
     *
     * @return string 
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * Set exitCode
     *
     * @param integer $exitCode
     * @return Build
     */
    public function setExitCode($exitCode)
    {
        $this->exitCode = $exitCode;
    
        return $this;
    }

    /**
     * Get exitCode
     *
     * @return integer 
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }

    /**
     * Set exitCodeText
     *
     * @param string $exitCodeText
     * @return Build
     */
    public function setExitCodeText($exitCodeText)
    {
        $this->exitCodeText = $exitCodeText;
    
        return $this;
    }

    /**
     * Get exitCodeText
     *
     * @return string 
     */
    public function getExitCodeText()
    {
        return $this->exitCodeText;
    }

    /**
     * Set port
     *
     * @param string $port
     * @return Build
     */
    public function setPort($port)
    {
        $this->port = $port;
    
        return $this;
    }

    /**
     * Get port
     *
     * @return string 
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Get url
     *
     * @return string 
     */
    public function getUrl()
    {
        if ($this->isRunning() && strlen($this->getHost()) > 0) {
            return sprintf('http://%s/', $this->getHost());
        }

        return null;
    }

    /**
     * Set branch
     *
     * @param \App\Model\Branch $branch
     * @return Build
     */
    public function setBranch(\App\Model\Branch $branch = null)
    {
        $this->branch = $branch;
    
        return $this;
    }

    /**
     * Get branch
     *
     * @return \App\Model\Branch 
     */
    public function getBranch()
    {
        return $this->branch;
    }
    
    /**
     * Add logs
     *
     * @param \App\Model\BuildLog $logs
     * @return Build
     */
    public function addLog(\App\Model\BuildLog $logs)
    {
        $this->logs[] = $logs;
    
        return $this;
    }

    /**
     * Remove logs
     *
     * @param \App\Model\BuildLog $logs
     */
    public function removeLog(\App\Model\BuildLog $logs)
    {
        $this->logs->removeElement($logs);
    }

    /**
     * Get logs
     *
     * @param string $type
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getLogs($type = null)
    {
        if (null === $type) {
            return $this->logs;
        }

        return array_filter($this->logs->toArray(), function($log) use ($type) {
            return $log->getType() === $type;
        });
    }

    /**
     * Set streamOutput
     *
     * @param boolean $streamOutput
     * @return Build
     */
    public function setStreamOutput($streamOutput)
    {
        $this->streamOutput = $streamOutput;
    
        return $this;
    }

    /**
     * Get streamOutput
     *
     * @return boolean 
     */
    public function getStreamOutput()
    {
        return $this->streamOutput;
    }

    /**
     * Set streamSteps
     *
     * @param boolean $streamSteps
     * @return Build
     */
    public function setStreamSteps($streamSteps)
    {
        $this->streamSteps = $streamSteps;
    
        return $this;
    }

    /**
     * Get streamSteps
     *
     * @return boolean 
     */
    public function getStreamSteps()
    {
        return $this->streamSteps;
    }

    /**
     * Set host
     *
     * @param string $host
     * @return Build
     */
    public function setHost($host)
    {
        $this->host = $host;
    
        return $this;
    }

    /**
     * Get host
     *
     * @return string 
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Set isDemo
     *
     * @param boolean $isDemo
     * @return Build
     */
    public function setIsDemo($isDemo)
    {
        $this->isDemo = $isDemo;
    
        return $this;
    }

    /**
     * Get isDemo
     *
     * @return boolean 
     */
    public function getIsDemo()
    {
        return $this->isDemo;
    }

    /**
     * Set demo
     *
     * @param \App\Model\Demo $demo
     * @return Build
     */
    public function setDemo(\App\Model\Demo $demo = null)
    {
        $this->demo = $demo;
    
        return $this;
    }

    /**
     * Get demo
     *
     * @return \App\Model\Demo 
     */
    public function getDemo()
    {
        return $this->demo;
    }

    /**
     * Set duration
     *
     * @param integer $duration
     * @return Build
     */
    public function setDuration($duration)
    {
        $this->duration = $duration;
    
        return $this;
    }

    /**
     * Get duration
     *
     * @return integer 
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * Set startTime
     *
     * @param \DateTime $startTime
     * @return Build
     */
    public function setStartTime($startTime)
    {
        $this->startTime = $startTime;
    
        return $this;
    }

    /**
     * Get startTime
     *
     * @return \DateTime 
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * Set endTime
     *
     * @param \DateTime $endTime
     * @return Build
     */
    public function setEndTime($endTime)
    {
        $this->endTime = $endTime;
    
        return $this;
    }

    /**
     * Get endTime
     *
     * @return \DateTime 
     */
    public function getEndTime()
    {
        return $this->endTime;
    }

    /**
     * Set memoryUsage
     *
     * @param integer $memoryUsage
     * @return Build
     */
    public function setMemoryUsage($memoryUsage)
    {
        $this->memoryUsage = $memoryUsage;
    
        return $this;
    }

    /**
     * Get memoryUsage
     *
     * @return integer 
     */
    public function getMemoryUsage()
    {
        return $this->memoryUsage;
    }

    /**
     * Set pid
     *
     * @param integer $pid
     * @return Build
     */
    public function setPid($pid)
    {
        $this->pid = $pid;
    
        return $this;
    }

    /**
     * Get pid
     *
     * @return integer 
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * Set containerName
     *
     * @param string $containerName
     * @return Build
     */
    public function setContainerName($containerName)
    {
        $this->containerName = $containerName;
    
        return $this;
    }

    /**
     * Get containerName
     *
     * @return string 
     */
    public function getContainerName()
    {
        return $this->containerName;
    }

    /**
     * Set allowRebuild
     *
     * @param boolean $allowRebuild
     * @return Build
     */
    public function setAllowRebuild($allowRebuild)
    {
        $this->allowRebuild = $allowRebuild;
    
        return $this;
    }

    /**
     * Get allowRebuild
     *
     * @return boolean 
     */
    public function getAllowRebuild()
    {
        return $this->allowRebuild;
    }
    /**
     * @var \App\Model\BuildScript
     */
    private $script;


    /**
     * Set script
     *
     * @param \App\Model\BuildScript $script
     * @return Build
     */
    public function setScript(\App\Model\BuildScript $script = null)
    {
        $this->script = $script;
    
        return $this;
    }

    /**
     * Get script
     *
     * @return \App\Model\BuildScript 
     */
    public function getScript()
    {
        return $this->script;
    }

    /**
     * @param string $name
     */
    public function hasOption($name)
    {
        return isset($this->options[$name]);
    }

    /**
     * @param string $name
     */
    public function getOption($name, $default = null)
    {
        return $this->hasOption($name) ? $this->options[$name] : $default;
    }

    /**
     * Set options
     *
     * @param array $options
     * @return Build
     */
    public function setOptions($options)
    {
        $this->options = $options;
    
        return $this;
    }

    /**
     * Get options
     *
     * @return array 
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set forceLocalBuildYml
     *
     * @param boolean $forceLocalBuildYml
     * @return Build
     */
    public function setForceLocalBuildYml($forceLocalBuildYml)
    {
        $this->forceLocalBuildYml = $forceLocalBuildYml;
    
        return $this;
    }

    /**
     * Get forceLocalBuildYml
     *
     * @return boolean 
     */
    public function getForceLocalBuildYml()
    {
        return $this->forceLocalBuildYml;
    }
    /**
     * @var \App\Model\BuildFailure
     */
    private $failure;


    /**
     * Set failure
     *
     * @param \App\Model\BuildFailure $failure
     * @return Build
     */
    public function setFailure(\App\Model\BuildFailure $failure = null)
    {
        $this->failure = $failure;
    
        return $this;
    }

    /**
     * Get failure
     *
     * @return \App\Model\BuildFailure 
     */
    public function getFailure()
    {
        return $this->failure;
    }

    /**
     * Set builderHost
     *
     * @param string $builderHost
     * @return Build
     */
    public function setBuilderHost($builderHost)
    {
        $this->builderHost = $builderHost;
    
        return $this;
    }

    /**
     * Get builderHost
     *
     * @return string 
     */
    public function getBuilderHost()
    {
        return $this->builderHost;
    }

    /**
     * Set pullRequest
     *
     * @param \App\Model\PullRequest $pullRequest
     * @return Build
     */
    public function setPullRequest(\App\Model\PullRequest $pullRequest = null)
    {
        $this->pullRequest = $pullRequest;
    
        return $this;
    }

    /**
     * Get pullRequest
     *
     * @return \App\Model\PullRequest 
     */
    public function getPullRequest()
    {
        return $this->pullRequest;
    }

    /**
     * Set commitUrl
     *
     * @param string $commitUrl
     * @return Build
     */
    public function setCommitUrl($commitUrl)
    {
        $this->commitUrl = $commitUrl;
    
        return $this;
    }

    /**
     * Get commitUrl
     *
     * @return string 
     */
    public function getCommitUrl()
    {
        return $this->commitUrl;
    }
}