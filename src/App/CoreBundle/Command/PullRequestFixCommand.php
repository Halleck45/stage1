<?php

namespace App\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class PullRequestFixCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('stage1:pull-request:fix')
            ->setDescription('Fixes malformed PullRequest entities');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $repository = $this->getContainer()->get('doctrine')->getRepository('Model:PullRequest');
        $manager = $this->getContainer()->get('doctrine')->getManager();

        foreach ($repository->findAll() as $pullRequest) {
            if (strlen($pullRequest->getUrl()) === 0) {
                $pullRequest->setUrl($pullRequest->getGithubUrl());
                $manager->persist($pullRequest);
            }
        }

        $manager->flush();
    }
}