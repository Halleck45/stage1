<?php

namespace App\CoreBundle\Provider;

/** @todo */
interface PayloadInterface
{
    /**
     * @return mixed
     */
    public function getRepositoryId();

    /**
     * @return string
     */
    public function getRepositoryFullName();

    /**
     * @return mixed
     */
    public function getDeliveryId();

    /**
     * @return string
     */
    public function getEvent();
}