<?php

namespace App\Model;

use App\CoreBundle\Provider\PayloadInterface;
use Doctrine\ORM\EntityRepository;

class ProjectRepository extends EntityRepository
{
    public function findOneByPayload(PayloadInterface $payload)
    {
        return $this->createQueryBuilder('p')
            ->where('p.providerName = :providerName and p.fullName = :fullName')
            ->setParameters([
                'providerName' => $payload->getProviderName(),
                'fullName' => $payload->getRepositoryFullName()
            ])
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