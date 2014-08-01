<?php

namespace App\Model;

class PullRequest
{
    protected $id;

    protected $number;

    protected $ref;

    protected $title;

    protected $open;

    protected $createdAt;

    protected $updatedAt;

    protected $project;

    protected $builds;

    protected $url;

    /** not persisted **/

    protected $lastBuild;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->builds = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function __toString()
    {
        return $this->getTitle();
    }

    /**
     * A Pull Request can not be a demo
     * 
     * @return boolean
     */
    public function getIsDemo()
    {
        return false;
    }

    public function getDomain()
    {
        return sprintf('pr-%d.%s', $this->getNumber(), $this->getProject()->getDomain());
    }

    /** @deprecated */
    public function getGithubUrl()
    {
        return vsprintf('https://github.com/%s/%s/pull/%d', [
            $this->getProject()->getGithubOwnerLogin(),
            $this->getProject()->getName(),
            $this->getNumber()
        ]);
    }

    /** @Buildable */
    public function hasRunningBuild()
    {
        return null !== $this->getRunningBuild();
    }

    /** @Buildable */
    public function getRunningBuild()
    {
        foreach ($this->getBuilds() as $build) {
            if ($build->isRunning()) {
                return $build;
            }
        }

        return null;
    }

    /** @Buildable */
    public function getNormName()
    {
        return preg_replace('/[^a-z0-9\-]/', '-', strtolower($this->getRef()));
    }
    
    /** @Buildable */
    public function getLastBuild()
    {
        return $this->lastBuild;
    }

    /** @Buildable */
    public function setLastBuild(Build $build)
    {
        $this->lastBuild = $build;

        return $this;
    } 

    /**
     * Set number
     *
     * @param integer $number
     * @return PullRequest
     */
    public function setNumber($number)
    {
        $this->number = $number;
    
        return $this;
    }

    /**
     * Get number
     *
     * @return integer 
     */
    public function getNumber()
    {
        return $this->number;
    }

    /**
     * Set title
     *
     * @param string $title
     * @return PullRequest
     */
    public function setTitle($title)
    {
        $this->title = $title;
    
        return $this;
    }

    /**
     * Get title
     *
     * @return string 
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set open
     *
     * @param boolean $open
     * @return PullRequest
     */
    public function setOpen($open)
    {
        $this->open = $open;
    
        return $this;
    }

    /**
     * Get open
     *
     * @return boolean 
     */
    public function getOpen()
    {
        return $this->open;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     * @return PullRequest
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
     * @return PullRequest
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
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Add builds
     *
     * @param \App\Model\Build $builds
     * @return PullRequest
     */
    public function addBuild(\App\Model\Build $builds)
    {
        $this->builds[] = $builds;
    
        return $this;
    }

    /**
     * Remove builds
     *
     * @param \App\Model\Build $builds
     */
    public function removeBuild(\App\Model\Build $builds)
    {
        $this->builds->removeElement($builds);
    }

    /**
     * Get builds
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getBuilds()
    {
        return $this->builds;
    }

    /**
     * Set project
     *
     * @param \App\Model\Project $project
     * @return PullRequest
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
     * Set ref
     *
     * @param string $ref
     * @return PullRequest
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
     * Set url
     *
     * @param string $url
     * @return PullRequest
     */
    public function setUrl($url)
    {
        $this->url = $url;
    
        return $this;
    }

    /**
     * Get url
     *
     * @return string 
     */
    public function getUrl()
    {
        return $this->url;
    }
}