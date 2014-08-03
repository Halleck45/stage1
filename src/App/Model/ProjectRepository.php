<?php

namespace App\Model;

use App\CoreBundle\Provider\PayloadInterface;
use Doctrine\ORM\EntityRepository;

class ProjectRepository extends EntityRepository
{
    public function findOneByPayload(PayloadInterface $payload)
    {
        return $this->createQueryBuilder('p')
            ->where('b.providerName = ? and b.fullName = ?')
            ->setParameters([$payload->getProviderName(), $payload->getRepositoryFullName()])
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneBySpec($spec)
    {
        if (is_numeric($spec)) {
            return $this->find((integer) $spec);
        }

        return $this->findOneBySlug($spec);
    }
}