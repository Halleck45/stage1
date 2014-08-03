<?php

namespace App\CoreBundle\Provider\GitHub;

use App\CoreBundle\Provider\PayloadInterface
use Symfony\Component\HttpFoundation\Request;

class Payload implements PayloadInterface
{
    /**
     * @var Request
     */
    private $request;

    /**
     * @var string
     */
    private $contents;

    /**
     * @var array
     */
    private $parsed;

    /**
     * @param Request $repositoryId
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->contents = $request->getContent();
        $this->parsed = json_decode($this->contents, true);
    }

    /**
     * @return string
     * 
     * @todo use ProviderInterface#getName
     */
    public function getProviderName()
    {
        return 'github';
    }

    /**
     * @return boolean
     */
    public function isPullRequest()
    {
        return isset($this->parsed['pull_request']);
    }

    /**
     * @return integer
     */
    public function getPullRequestNumber()
    {
        if (!$this->isPullRequest()) {
            throw new RuntimeException('Called PR specific method on a non-PR payload');
        }

        return $this->parsed['pull_request']['number'];
    }

    /**
     * @return string
     */
    public function isBuildable()
    {
        if ($this->isPullRequest()) {
            return in_array($this->parsed['pull_request']['action'], ['opened', 'synchronize']);
        }

        return true;
    }

    /**
     * @return boolean
     */
    public function hasRef()
    {
        return $this->isPullRequest() || isset($this->parsed['ref']);
    }

    /**
     * @return string
     */
    public function getRef()
    {
        return $this->isPullRequest()
            ? sprintf('pull/%d/head', $this->getPullRequestNumber())
            : $this->parsed['ref'];
    }

    /**
     * @return string
     * 
     * @todo check if this is implementable for a PR
     */
    public function getHash()
    {
        if ($this->isPullRequest()) {
            throw new RuntimeException('Called branch specific method on a non-branch payload');
        }
        
        return $this->parsed['after'];
    }

    /**
     * @return boolean
     */
    public function isDummy()
    {
        return isset($this->parsed['zen']);
    }

    /**
     * @return mixed
     */
    public function getRepositoryId()
    {
        return $this->parsed['repository']['id'];
    }

    /**
     * @return string
     */
    public function getRepositoryName()
    {
        $repository = $this->parsed['repository'];

        return sprintf('%s/%s', $repository['owner']['name'], $repository['name']);
    }

    /**
     * @return mixed
     */
    public function getDeliveryId()
    {
        return $this->request->headers->get('X-GitHub-Delivery');
    }

    /**
     * @return string
     */
    public function getEvent()
    {
        return $this->request->headers->get('X-GitHub-Event');
    }
}