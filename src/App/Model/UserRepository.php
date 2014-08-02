<?php

namespace App\Model;

use Doctrine\ORM\EntityRepository;

class UserRepository extends EntityRepository
{
    public function findOneBySpec($spec)
    {
        if (is_numeric($spec)) {
            return $this->find((integer) $spec);
        }

        return $this->findOneByUsername($spec);
    }
}