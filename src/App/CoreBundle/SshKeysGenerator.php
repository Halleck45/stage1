<?php

namespace App\CoreBundle;

use Symfony\Component\Process\ProcessBuilder;
use App\Model\Project;
use RuntimeException;

class SshKeysGenerator
{
    public function dump(Project $project, $file = null)
    {
        if (null === $file) {
            $file = tempnam(sys_get_temp_dir(), 'build-ssh-keys-');
        }

        file_put_contents($file, $project->getPrivateKey());
        file_put_contents($file.'.pub', $project->getPublicKey());

        return $file;
    }

    public function generate($comment = '')
    {
        $private = tempnam(sys_get_temp_dir(), 'ssh-keygen-');
        $public  = $private.'.pub';

        unlink($private);

        $builder = new ProcessBuilder(['/usr/bin/ssh-keygen', '-q', '-N', '', '-t', 'rsa', '-f', $private, '-C', $comment]);
        $process = $builder->getProcess();

        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return [
            'private' => trim(file_get_contents($private)),
            'public'  => trim(file_get_contents($public)),
        ];
    }
}