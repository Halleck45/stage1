<?php

namespace App\Model;

use App\CoreBundle\Provider\ProviderInterface;
use Doctrine\Common\Collections\ArrayCollection;
use FOS\UserBundle\Model\User as BaseUser;
use Serializable;

class User extends BaseUser implements Serializable
{
    const STATUS_DISABLED = 0;

    const STATUS_ENABLED = 1;

    const STATUS_WAITING_LIST = 2;

    const STATUS_BETA = 3;

    protected $id;

    protected $githubId;

    /**
     * @todo the access token management could be refactored
     *       using an AccessToken entity and a OneToMany relation
     */
    protected $providersAccessTokens;

    protected $providersScopes;

    protected $createdAt;

    protected $updatedAt;

    protected $projects;

    protected $status = 1;

    protected $waitingList = 0;

    protected $channel;

    protected $publicKey;

    protected $privateKey;

    protected $betaSignup;

    /** @deprecated */
    protected $accessToken;

    /** @deprecated */
    protected $accessTokenScope;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->providersScopes = [];
        $this->providersAccessTokens = [];
        
        $this->projects = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string) $this->getUsername();
    }

    /**
     * @param ProviderInterface
     * 
     * @return ArrayCollection
     */
    public function getProjectsByProvider(ProviderInterface $provider)
    {
        return $this->getProjects()->filter(function($project) use ($provider) {
            return $project->getProviderName() === $provider->getName();
        });
    }

    /**
     * @param string $provider
     * 
     * @return string|null
     */
    public function getProviderAccessToken($provider)
    {
        return array_key_exists($provider, $this->providersAccessTokens)
            ? $this->providersAccessTokens[$provider]
            : null;
    }

    /**
     * @param string $provider
     * @param string $accessToken
     * 
     * @return User
     */
    public function setProviderAccessToken($provider, $accessToken)
    {
        $this->providersAccessTokens[$provider] = $accessToken;

        return $this;
    }

    /**
     * @param string $provider
     * 
     * @return boolean
     */
    public function hasProviderAccessToken($provider)
    {
        return array_key_exists($provider, $this->getProvidersAccessTokens());
    }

    /**
     * @param string $provider
     * @param string $scope
     * 
     * @return boolean
     */
    public function hasProviderScope($provider, $scope)
    {
        return in_array($scope, $this->getProviderScopes($provider));
    }

    /**
     * @param string $provider
     * 
     * @return string|null
     */
    public function getProviderScopes($provider)
    {
        return array_key_exists($provider, $this->providersScopes)
            ? $this->providersScopes[$provider]
            : [];
    }

    /**
     * @param string $provider
     * @param array  $scopes
     * 
     * @return User
     */
    public function setProviderScopes($provider, array $scopes)
    {
        $this->providersScopes[$provider] = $scopes;

        return $this;
    }

    public function hasPrivateProjects()
    {
        foreach ($this->getProjects() as $project) {
            if ($project->getIsPrivate()) {
                return true;
            }
        }

        return false;
    }

    /** @deprecated */
    public function addAccessTokenScopes($scopes)
    {
        $hasScopes = explode(',', $this->getAccessTokenScope());
        $hasScopes = array_merge($hasScopes, $scopes);
        $hasScopes = array_unique($hasScopes);

        $this->setAccessTokenScope(implode(',', $hasScopes));
    }

    /**
     * @param string $provider
     * @param string $name
     */
    public function hasAccessTokenScope($provider, $name)
    {
        return in_array($name, $this->getProviderScopes($name));
    }

    public function serialize()
    {
        return serialize([$this->getId(), $this->getUsername()]);
    }

    public function unserialize($data)
    {
        $data = unserialize($data);

        $this->id = $data[0];
        $this->setUsername($data[1]);
    }

    /**
     * Set githubId
     *
     * @param integer $githubId
     * @return User
     */
    public function setGithubId($githubId)
    {
        $this->githubId = $githubId;
    
        return $this;
    }

    /**
     * Get githubId
     *
     * @return integer 
     */
    public function getGithubId()
    {
        return $this->githubId;
    }

    /**
     * Set accessToken
     *
     * @deprecated
     * 
     * @param string $accessToken
     * @return User
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    
        return $this;
    }

    /**
     * Get accessToken
     *
     * @deprecated
     * 
     * @return string 
     */
    public function getAccessToken()
    {
        return $this->accessToken;
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
     * Set createdAt
     *
     * @param \DateTime $createdAt
     * @return User
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
     * @return User
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
     * Add projects
     *
     * @param \App\Model\Project $projects
     * @return User
     */
    public function addProject(\App\Model\Project $projects)
    {
        $this->projects[] = $projects;
    
        return $this;
    }

    /**
     * Remove projects
     *
     * @param \App\Model\Project $projects
     */
    public function removeProject(\App\Model\Project $projects)
    {
        $this->projects->removeElement($projects);
    }

    /**
     * Get projects
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getProjects()
    {
        return $this->projects;
    }

    /**
     * Get private projects
     * 
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getPrivateProjects()
    {
        return $this->projects->filter(function($project) {
            return $project->getGithubPrivate();
        });
    }

    /**
     * Set status
     *
     * @param integer $status
     * @return User
     */
    public function setStatus($status)
    {
        $this->status = $status;
    
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
     * Set waitingList
     *
     * @param integer $waitingList
     * @return User
     */
    public function setWaitingList($waitingList)
    {
        $this->waitingList = $waitingList;
    
        return $this;
    }

    /**
     * Get waitingList
     *
     * @return integer 
     */
    public function getWaitingList()
    {
        return $this->waitingList;
    }

    /**
     * Set channel
     *
     * @param string $channel
     * @return User
     */
    public function setChannel($channel)
    {
        $this->channel = $channel;
    
        return $this;
    }

    /**
     * Get channel
     *
     * @return string 
     */
    public function getChannel($raw = false)
    {
        if ($raw || strlen($this->channel) > 0) {
            return $this->channel;
        }

        return 'user.'.$this->getId();
    }

    /**
     * Set publicKey
     *
     * @param string $publicKey
     * @return User
     */
    public function setPublicKey($publicKey)
    {
        $this->publicKey = $publicKey;
    
        return $this;
    }

    /**
     * Get publicKey
     *
     * @return string 
     */
    public function getPublicKey()
    {
        return $this->publicKey;
    }

    /**
     * Set privateKey
     *
     * @param string $privateKey
     * @return User
     */
    public function setPrivateKey($privateKey)
    {
        $this->privateKey = $privateKey;
    
        return $this;
    }

    /**
     * Get privateKey
     *
     * @return string 
     */
    public function getPrivateKey()
    {
        return $this->privateKey;
    }

    /**
     * Set accessTokenScope
     * 
     * @deprecated
     *
     * @param string $accessTokenScope
     * @return User
     */
    public function setAccessTokenScope($accessTokenScope)
    {
        $this->accessTokenScope = $accessTokenScope;
    
        return $this;
    }

    /**
     * Get accessTokenScope
     * 
     * @deprecated
     *
     * @return string 
     */
    public function getAccessTokenScope()
    {
        return $this->accessTokenScope;
    }

    /**
     * Set betaSignup
     *
     * @param \App\Model\BetaSignup $betaSignup
     * @return User
     */
    public function setBetaSignup(\App\Model\BetaSignup $betaSignup = null)
    {
        $this->betaSignup = $betaSignup;
    
        return $this;
    }

    /**
     * Get betaSignup
     *
     * @return \App\Model\BetaSignup 
     */
    public function getBetaSignup()
    {
        return $this->betaSignup;
    }
    /**
     * @var array
     */
    private $providerAccessTokens;

    /**
     * @var array
     */
    private $providerScopes;


    /**
     * Set providerAccessTokens
     *
     * @param array $providerAccessTokens
     * @return User
     */
    public function setProviderAccessTokens($providerAccessTokens)
    {
        $this->providerAccessTokens = $providerAccessTokens;
    
        return $this;
    }

    /**
     * Get providerAccessTokens
     *
     * @return array 
     */
    public function getProviderAccessTokens()
    {
        return $this->providerAccessTokens;
    }

    /**
     * Set providersAccessTokens
     *
     * @param array $providersAccessTokens
     * @return User
     */
    public function setProvidersAccessTokens($providersAccessTokens)
    {
        $this->providersAccessTokens = $providersAccessTokens;
    
        return $this;
    }

    /**
     * Get providersAccessTokens
     *
     * @return array 
     */
    public function getProvidersAccessTokens()
    {
        return $this->providersAccessTokens;
    }

    /**
     * Set providersScopes
     *
     * @param array $providersScopes
     * @return User
     */
    public function setProvidersScopes($providersScopes)
    {
        $this->providersScopes = $providersScopes;
    
        return $this;
    }

    /**
     * Get providersScopes
     *
     * @return array 
     */
    public function getProvidersScopes()
    {
        return $this->providersScopes;
    }
}