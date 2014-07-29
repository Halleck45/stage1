<?php

namespace App\Model;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserRepository extends EntityRepository implements UserProviderInterface
{
    public function findOneBySpec($spec)
    {
        if (is_numeric($spec)) {
            return $this->find((integer) $spec);
        }

        return $this->findOneByUsername($spec);
    }

    public function findOneByGithubUsername($username)
    {
        return $this->findOneByUsername($username);
    }

    public function loadUserByUsername($username) {
        return $this->getEntityManager()
            ->createQuery('SELECT u FROM
                Model:User u
                WHERE u.username = :username
                OR u.email = :username')
            ->setParameters(array(
                'username' => $username
            ))
            ->getOneOrNullResult();
    }

    public function refreshUser(UserInterface $user) {
        return $this->loadUserByUsername($user->getUsername());
    }

    public function supportsClass($class) {
        return $class === 'App\Model\User';
    }
}