<?php

namespace App\Model;

use Docker\Context\Context;
use Docker\Context\ContextBuilder;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\OptionsResolver\OptionsResolver;
use InvalidArgumentException;

/**
 * Project
 */
class Project implements WebsocketRoutable
{
    const STATUS_DISABLED = 0;

    const STATUS_ENABLED = 1;

    const STATUS_HOLD = 2;

    protected $id;

    protected $name;

    protected $slug;

    protected $fullName;

    protected $isPrivate;

    protected $providerName;

    protected $providerData;

    protected $gitUrl;

    protected $builds;

    protected $createdAt;

    protected $updatedAt;

    protected $lastBuildAt;

    protected $lastBuildRef;

    protected $publicKey;

    protected $privateKey;

    protected $masterPassword;

    protected $users;

    protected $branches;

    protected $pullRequests;

    protected $status = 1;

    protected $env;

    protected $urls;

    protected $dockerBaseImage = 'symfony2:latest';

    protected $settings;

    protected $domain;

    protected $organization;

    /** start github fields */

    protected $cloneUrl;

    protected $sshUrl;

    protected $keysUrl;

    protected $hooksUrl;

    protected $contentsUrl;

    protected $githubId;

    protected $githubFullName;

    protected $githubOwnerLogin;

    protected $githubHookId;

    protected $githubDeployKeyId;

    protected $githubPrivate;

    protected $githubUrl;

    /** end GitHub fields */

    public function getDefaultBuildOptions()
    {
        $options = new OptionsResolver();

        $normalize = function($array) {
            return array_filter(array_map('trim', $array), function($item) {
                return strlen($item) > 0;
            });
        };

        $options->setDefaults([
            'image' => $this->getDockerBaseImage(),
            'env' => $normalize($this->getContainerEnv()),
            'urls' => $normalize(explode(PHP_EOL, $this->getUrls())),
            'writable' => [],
            'build' => [],
            'run' => [],
            'script' => [], // @todo this is legacy but needs to be here else OptionsResolver will die because the option is not known
            'writables' =>[],
            'options' => [],
            'dockerfile' => [],
        ]);

        $options->setRequired(['image']);

        return $options;
    }

    /**
     * @param string $identityRoot
     * 
     * @todo move to a SshKey\Dumper?
     */
    public function dumpSshKeys($identityRoot, $owner = 'root', $put = 'file_put_contents', $exec = 'exec')
    {
        $put($identityRoot.'/id_project', $this->getPrivateKey());
        $put($identityRoot.'/id_project.pub', $this->getPublicKey());

        if (null !== $this->getOrganization()) {
            $put($identityRoot.'/id_organization', $this->getOrganization()->getPrivateKey());
            $put($identityRoot.'/id_organization.pub', $this->getOrganization()->getPublicKey());
        }

        foreach ($this->getUsers() as $user) {
            $put($identityRoot.'/id_'.$user->getUsername(), $user->getPrivateKey());
            $put($identityRoot.'/id_'.$user->getUsername().'.pub', $user->getPublicKey());            
        }

        $exec('chmod -R 0600 '.$identityRoot);
        $exec('chown -R '.$owner.':'.$owner.' '.$identityRoot);
    }

    /**
     * @param string $identityRoot
     * 
     * @todo github refactoring
     */
    public function getSshConfig($identityRoot)
    {
        $identities = [$identityRoot.'/id_project'];

        if (null !== $this->getOrganization()) {
            $identities[] = $identityRoot.'/id_organization';
        }

        foreach ($this->getUsers() as $user) {
            $identities[] = $identityRoot.'/id_'.$user->getUsername();
        }

        $sshIdentityFile = implode(PHP_EOL, array_map(function($identity) {
            return 'IdentityFile '.$identity;
        }, $identities));

        return <<<SSH
$sshIdentityFile

StrictHostKeyChecking no
UserKnownHostsFile /dev/null
LogLevel QUIET

Host github.com
    Hostname github.com
    User git
SSH;
    }

    // @todo the base container can (and should?) be built during project import
    //       that's one lest step during the build
    // @todo also, move that to a BuildContext
    public function getDockerContextBuilder($identityRoot = '/root/.ssh')
    {
        $env  = 'PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"'.PHP_EOL;
        $env .= 'SYMFONY_ENV=prod'.PHP_EOL;
        $env .= $this->getEnv();

        $builder = new ContextBuilder();
        $builder->setFormat(Context::FORMAT_TAR);
        $builder->add('/etc/environment', $env);

        $builder->add('/root/.ssh/config', $this->getSshConfig('/root/.ssh'));
        $this->dumpSshKeys($identityRoot, 'root', [$builder, 'add'], [$builder, 'run']);

        if ($this->getSettings() && strlen($this->getSettings()->getBuildYml()) > 0) {
            $builder->add('/root/stage1_local.yml', $this->getSettings()->getBuildYml());
        }

        return $builder;
    }

    /**
     * @return string
     */
    public function getDomain()
    {
        if (null === $this->domain) {
            list($org, $project) = explode('/', $this->getFullName());

            $org = preg_replace('/[^a-z0-9\-]/', '-', strtolower($org));
            $project = preg_replace('/[^a-z0-9\-]/', '-', strtolower($project));

            return $org.'.'.$project;            
        }

        return $this->domain;
    }

    /**
     * Set domain
     *
     * @param string $domain
     * @return Project
     */
    public function setDomain($domain)
    {
        $this->domain = $domain;
    
        return $this;
    }

    /**
     * @return string
     */
    public function getAccessList()
    {
        return 'auth:'.$this->getSlug();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return json_encode($this->asMessage());
    }

    /**
     * @return string
     */
    public function getChannel()
    {
        return 'project.'.$this->getId();
    }

    public function asMessage()
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'nb_pending_builds' => count($this->getPendingBuilds()),
        ];
    }

    public function getPendingBuilds()
    {
        return $this->getBuilds()->filter(function($build) {
            return $build->isPending();
        });
    }

    public function getRunningBuilds()
    {
        return $this->getBuilds()->filter(function($build) {
            return $build->isRunning();
        });
    }

    public function getBuildingBuilds()
    {
        return $this->getBuilds()->filter(function($build) {
            return $build->isBuilding();
        });
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
     * Set name
     *
     * @param string $name
     * @return Project
     */
    public function setName($name)
    {
        $this->name = $name;
    
        return $this;
    }

    /**
     * Get name
     *
     * @return string 
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     * @return Project
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
     * @return Project
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
     * Set lastBuildAt
     *
     * @param \DateTime $lastBuildAt
     * @return Project
     */
    public function setLastBuildAt($lastBuildAt)
    {
        $this->lastBuildAt = $lastBuildAt;
    
        return $this;
    }

    /**
     * Get lastBuildAt
     *
     * @return \DateTime 
     */
    public function getLastBuildAt()
    {
        return $this->lastBuildAt;
    }

    /**
     * Set lastBuildRef
     *
     * @param string $lastBuildRef
     * @return Project
     */
    public function setLastBuildRef($lastBuildRef)
    {
        $this->lastBuildRef = $lastBuildRef;
    
        return $this;
    }

    /**
     * Get lastBuildRef
     *
     * @return string 
     */
    public function getLastBuildRef()
    {
        return $this->lastBuildRef;
    }
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->builds = new \Doctrine\Common\Collections\ArrayCollection();
        $this->users = new \Doctrine\Common\Collections\ArrayCollection();
        $this->branches = new \Doctrine\Common\Collections\ArrayCollection();
        $this->settings = new ProjectSettings();
    }
    
    /**
     * Add builds
     *
     * @param \App\Model\Build $builds
     * @return Project
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
     * Set publicKey
     *
     * @param string $publicKey
     * @return Project
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
     * @return Project
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
     * Set slug
     *
     * @param string $slug
     * @return Project
     */
    public function setSlug($slug)
    {
        $this->slug = $slug;
    
        return $this;
    }

    /**
     * Get slug
     *
     * @return string 
     */
    public function getSlug()
    {
        return $this->slug;
    }

    /**
     * Set masterPassword
     *
     * @param string $masterPassword
     * @return Project
     */
    public function setMasterPassword($masterPassword)
    {
        $this->masterPassword = $masterPassword;
    
        return $this;
    }

    /**
     * Get masterPassword
     *
     * @return string 
     */
    public function getMasterPassword()
    {
        return $this->masterPassword;
    }

    /**
     * @return boolean
     */
    public function hasMasterPassword()
    {
        return strlen($this->getMasterPassword()) > 0;
    }

    /**
     * Add users
     *
     * @param \App\Model\User $users
     * @return Project
     */
    public function addUser(\App\Model\User $users)
    {
        $this->users[] = $users;
    
        return $this;
    }

    /**
     * Remove users
     *
     * @param \App\Model\User $users
     */
    public function removeUser(\App\Model\User $users)
    {
        $this->users->removeElement($users);
    }

    /**
     * Get users
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * Add branches
     *
     * @param \App\Model\Branch $branch
     * @return Project
     */
    public function addBranch(\App\Model\Branch $branch)
    {
        return $this->addBranche($branch);
    }

    /**
     * Remove branches
     *
     * @param \App\Model\Branch $branch
     */
    public function removeBranch(\App\Model\Branch $branch)
    {
        return $this->removeBranche($branch);
    }

    /**
     * Get branches not marked as deleted
     * 
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getActiveBranches()
    {
        return $this->getBranches()->filter(function($branch) {
            return !$branch->getDeleted();
        });
    }

    /**
     * Get pull requests marked as open
     * 
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getActivePullRequests()
    {
        return $this->getPullRequests()->filter(function($pullRequest) { return $pullRequest->getOpen(); });
    }

    /**
     * Get branches
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getBranches()
    {
        return $this->branches;
    }

    /**
     * Add branches
     *
     * @param \App\Model\Branch $branches
     * @return Project
     */
    public function addBranche(\App\Model\Branch $branches)
    {
        $this->branches[] = $branches;
    
        return $this;
    }

    /**
     * Remove branches
     *
     * @param \App\Model\Branch $branches
     */
    public function removeBranche(\App\Model\Branch $branches)
    {
        $this->branches->removeElement($branches);
    }

    /**
     * Set status
     *
     * @param integer $status
     * @return Project
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
     * @return array
     */
    public function getContainerEnv()
    {        
        $env = explode(PHP_EOL, $this->getEnv());
        $env = array_map('trim', $env);
        $env = array_filter($env, function($e) {
            return strlen($e) > 0;
        });

        return $env;
    }

    /**
     * @return array
     */
    public function getParsedEnv()
    {
        $parsedEnv = [];

        foreach ($this->getContainerEnv() as $var) {
            $var = explode('=', $var);

            if (count($var) === 2) {
                $parsedEnv[$var[0]] = $var[1];
            }
        }

        return $parsedEnv;
    }

    /**
     * Set env
     *
     * @param string $env
     * @return Project
     */
    public function setEnv($env)
    {
        $this->env = $env;
    
        return $this;
    }

    /**
     * Get env
     *
     * @return string 
     */
    public function getEnv()
    {
        return $this->env;
    }

    /**
     * Set dockerBaseImage
     *
     * @param string $dockerBaseImage
     * @return Project
     */
    public function setDockerBaseImage($dockerBaseImage)
    {
        $this->dockerBaseImage = $dockerBaseImage;
    
        return $this;
    }

    /**
     * Get dockerBaseImage
     *
     * @return string 
     */
    public function getDockerBaseImage()
    {
        return $this->dockerBaseImage;
    }

    /**
     * Set urls
     *
     * @param string $urls
     * @return Project
     */
    public function setUrls($urls)
    {
        $this->urls = $urls;
    
        return $this;
    }

    /**
     * Get urls
     *
     * @return string 
     */
    public function getUrls()
    {
        return $this->urls;
    }

    /**
     * Set settings
     *
     * @param \App\Model\ProjectSettings $settings
     * @return Project
     */
    public function setSettings(\App\Model\ProjectSettings $settings = null)
    {
        $this->settings = $settings;
    
        return $this;
    }

    /**
     * Get settings
     *
     * @return \App\Model\ProjectSettings 
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * Set organization
     *
     * @param \App\Model\Organization $organization
     * @return Project
     */
    public function setOrganization(\App\Model\Organization $organization = null)
    {
        $this->organization = $organization;
    
        return $this;
    }

    /**
     * Get organization
     *
     * @return \App\Model\Organization 
     */
    public function getOrganization()
    {
        return $this->organization;
    }

    /**
     * Add pullRequests
     *
     * @param \App\Model\PullRequest $pullRequests
     * @return Project
     */
    public function addPullRequest(\App\Model\PullRequest $pullRequests)
    {
        $this->pullRequests[] = $pullRequests;
    
        return $this;
    }

    /**
     * Remove pullRequests
     *
     * @param \App\Model\PullRequest $pullRequests
     */
    public function removePullRequest(\App\Model\PullRequest $pullRequests)
    {
        $this->pullRequests->removeElement($pullRequests);
    }

    /**
     * Get pullRequests
     *
     * @return PullRequest[] 
     */
    public function getPullRequests()
    {
        return $this->pullRequests;
    }

    /**
     * Set fullName
     *
     * @param string $fullName
     * @return Project
     */
    public function setFullName($fullName)
    {
        $this->fullName = $fullName;
    
        return $this;
    }

    /**
     * Get fullName
     * 
     * @return string
     */
    public function getFullName()
    {
        return $this->fullName;
    }

    /**
     * Set providerName
     *
     * @param string $providerName
     * @return Project
     */
    public function setProviderName($providerName)
    {
        $this->providerName = $providerName;
    
        return $this;
    }

    /**
     * Get providerName
     *
     * @return string 
     */
    public function getProviderName()
    {
        return $this->providerName;
    }

    /**
     * Set providerData
     *
     * @param string $providerData
     * @param mixed $value
     * 
     * @return Project
     */
    public function setProviderData($providerData, $value = null)
    {
        if (null !== $value) {
            $this->providerData[$providerData] = $value;
        } else {
            $this->providerData = $providerData;
        }
    
        return $this;
    }

    /**
     * Get providerData
     *
     * @param string|null $key
     * 
     * @return array|mixed 
     */
    public function getProviderData($key = null)
    {
        if (null !== $key) {
            if (array_key_exists($key, $this->providerData)) {
                return $this->providerData[$key];
            }

            throw new InvalidArgumentException('Unknown provider data key "'.$key.'"');
        }

        return $this->providerData;
    }

    /**
     * Set githubPrivate
     *
     * @param boolean $githubPrivate
     * @return Project
     */
    public function setGithubPrivate($githubPrivate)
    {
        $this->githubPrivate = $githubPrivate;
    
        return $this;
    }

    /**
     * Get githubPrivate
     *
     * @return boolean 
     */
    public function getGithubPrivate()
    {
        return $this->githubPrivate;
    }

    /**
     * Set contentsUrl
     *
     * @param string $contentsUrl
     * @return Project
     */
    public function setContentsUrl($contentsUrl)
    {
        $this->contentsUrl = $contentsUrl;
    
        return $this;
    }

    /**
     * Get contentsUrl
     *
     * @return string 
     */
    public function getContentsUrl()
    {
        return $this->contentsUrl;
    }

    /**
     * Set githubId
     *
     * @param integer $githubId
     * @return Project
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
     * Set githubFullName
     *
     * @param string $githubFullName
     * @return Project
     */
    public function setGithubFullName($githubFullName)
    {
        $this->githubFullName = $githubFullName;
    
        return $this;
    }

    /**
     * Get githubFullName
     *
     * @return string 
     */
    public function getGithubFullName()
    {
        return $this->githubFullName;
    }

    /**
     * Set githubOwnerLogin
     *
     * @param string $githubOwnerLogin
     * @return Project
     */
    public function setGithubOwnerLogin($githubOwnerLogin)
    {
        $this->githubOwnerLogin = $githubOwnerLogin;
    
        return $this;
    }

    /**
     * Get githubOwnerLogin
     *
     * @return string 
     */
    public function getGithubOwnerLogin()
    {
        return $this->githubOwnerLogin;
    }

    /**
     * Set githubHookId
     *
     * @param integer $githubHookId
     * @return Project
     */
    public function setGithubHookId($githubHookId)
    {
        $this->githubHookId = $githubHookId;
    
        return $this;
    }

    /**
     * Get githubHookId
     *
     * @return integer 
     */
    public function getGithubHookId()
    {
        return $this->githubHookId;
    }

    /**
     * Set githubDeployKeyId
     *
     * @param integer $githubDeployKeyId
     * @return Project
     */
    public function setGithubDeployKeyId($githubDeployKeyId)
    {
        $this->githubDeployKeyId = $githubDeployKeyId;
    
        return $this;
    }

    /**
     * Get githubDeployKeyId
     *
     * @return integer 
     */
    public function getGithubDeployKeyId()
    {
        return $this->githubDeployKeyId;
    }

    /**
     * Set githubUrl
     *
     * @param string $githubUrl
     * @return Project
     */
    public function setGithubUrl($githubUrl)
    {
        $this->githubUrl = $githubUrl;
    
        return $this;
    }

    /**
     * Get githubUrl
     *
     * @return string 
     */
    public function getGithubUrl()
    {
        return $this->githubUrl;
    }

    /**
     * Set cloneUrl
     *
     * @param string $cloneUrl
     * @return Project
     */
    public function setCloneUrl($cloneUrl)
    {
        $this->cloneUrl = $cloneUrl;
    
        return $this;
    }

    /**
     * Get cloneUrl
     *
     * @return string 
     */
    public function getCloneUrl()
    {
        return $this->cloneUrl;
    }

    /**
     * Set sshUrl
     *
     * @param string $sshUrl
     * @return Project
     */
    public function setSshUrl($sshUrl)
    {
        $this->sshUrl = $sshUrl;
    
        return $this;
    }

    /**
     * Get sshUrl
     *
     * @return string 
     */
    public function getSshUrl()
    {
        return $this->sshUrl;
    }

    /**
     * Set keysUrl
     *
     * @param string $keysUrl
     * @return Project
     */
    public function setKeysUrl($keysUrl)
    {
        $this->keysUrl = $keysUrl;
    
        return $this;
    }

    /**
     * Get keysUrl
     *
     * @return string 
     */
    public function getKeysUrl()
    {
        return $this->keysUrl;
    }

    /**
     * Set hooksUrl
     *
     * @param string $hooksUrl
     * @return Project
     */
    public function setHooksUrl($hooksUrl)
    {
        $this->hooksUrl = $hooksUrl;
    
        return $this;
    }

    /**
     * Get hooksUrl
     *
     * @return string 
     */
    public function getHooksUrl()
    {
        return $this->hooksUrl;
    }

    /**
     * Set isPrivate
     *
     * @param boolean $isPrivate
     * @return Project
     */
    public function setIsPrivate($isPrivate)
    {
        $this->isPrivate = $isPrivate;
    
        return $this;
    }

    /**
     * Get isPrivate
     *
     * @return boolean 
     */
    public function getIsPrivate()
    {
        return $this->isPrivate;
    }

    /**
     * Set gitUrl
     *
     * @param string $gitUrl
     * @return Project
     */
    public function setGitUrl($gitUrl)
    {
        $this->gitUrl = $gitUrl;
    
        return $this;
    }

    /**
     * Get gitUrl
     *
     * @return string 
     */
    public function getGitUrl()
    {
        return $this->gitUrl;
    }
}