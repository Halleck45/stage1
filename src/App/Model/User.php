<?php

namespace App\Model;

use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface, \Serializable
{
    const STATUS_DISABLED = 0;

    const STATUS_ENABLED = 1;

    const STATUS_WAITING_LIST = 2;

    const STATUS_BETA = 3;

    protected $id;

    protected $githubId;

    protected $username;

    protected $email;

    protected $accessToken;

    protected $createdAt;

    protected $updatedAt;

    protected $lastLoginAt;

    protected $projects;

    protected $status = 1;

    protected $waitingList = 0;

    protected $channel;

    protected $publicKey;

    protected $privateKey;

    protected $accessTokenScope;

    protected $betaSignup;

    protected $roles = [];

    public function __toString()
    {
        return (string) $this->username;
    }

    public function hasPrivateProjects()
    {
        foreach ($this->getProjects() as $project) {
            if ($project->getGithubPrivate()) {
                return true;
            }
        }

        return false;
    }

    public function addAccessTokenScopes($scopes)
    {
        $hasScopes = explode(',', $this->getAccessTokenScope());
        $hasScopes = array_merge($hasScopes, $scopes);
        $hasScopes = array_unique($hasScopes);

        $this->setAccessTokenScope(implode(',', $hasScopes));
    }

    /**
     * @param string $name
     */
    public function hasAccessTokenScope($name)
    {
        $scopes = explode(',', $this->getAccessTokenScope());

        return false !== array_search($name, $scopes);
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

    // public function getRoles()
    // {
    //     $roles = ['ROLE_USER'];

    //     if (in_array($this->getUsername(), array('ubermuda', 'pocky'))) {
    //         $roles[] = 'ROLE_ADMIN';
    //         $roles[] = 'ROLE_DEMO';
    //     }

    //     return $roles;
    // }

    public function getPassword()
    {
        return null;
    }

    public function getSalt()
    {
        return null;
    }

    public function eraseCredentials()
    {
        return true;
    }

    static public function fromGithubResponse(array $response)
    {
        $user = new static();
        $user->setGithubId($response['id']);
        $user->setUsername($response['login']);

        if (isset($response['email']) && strlen($response['email']) > 0) {
            $user->setEmail($response['email']);
        }

        return $user;
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
     * Set username
     *
     * @param string $username
     * @return User
     */
    public function setUsername($username)
    {
        $this->username = $username;
    
        return $this;
    }

    /**
     * Get username
     *
     * @return string 
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set email
     *
     * @param string $email
     * @return User
     */
    public function setEmail($email)
    {
        $this->email = $email;
    
        return $this;
    }

    /**
     * Get email
     *
     * @return string 
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Set accessToken
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
     * Set lastLoginAt
     *
     * @param \DateTime $lastLoginAt
     * @return User
     */
    public function setLastLoginAt($lastLoginAt)
    {
        $this->lastLoginAt = $lastLoginAt;
    
        return $this;
    }

    /**
     * Get lastLoginAt
     *
     * @return \DateTime 
     */
    public function getLastLoginAt()
    {
        return $this->lastLoginAt;
    }
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->projects = new \Doctrine\Common\Collections\ArrayCollection();
        $this->roles = [];
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
     * Set roles
     *
     * @param array $roles
     * @return User
     */
    public function setRoles($roles)
    {
        $this->roles = $roles;
    
        return $this;
    }

    /**
     * Add a role
     * 
     * @param string $role
     * @return User
     */
    public function addRole($role)
    {
        $this->roles[] = $role;
        $this->roles = array_unique($this->roles);

        return $this;
    }

    /**
     * Remove a role
     * 
     * @param string $role
     * @return User
     */
    public function removeRole($role)
    {
        if (false !== $index = array_search($role, $this->roles)) {
            unset($this->roles[$index]);
        }

        return $this;
    }

    /**
     * Get roles
     *
     * @return array 
     */
    public function getRoles($raw = false)
    {
        if (null === $this->roles && !$raw) {
            return ['ROLE_USER'];
        }

        return $this->roles;
    }
}