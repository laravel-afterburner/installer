<?php

namespace Afterburner\Installer\Installers;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class CoreInstaller
{
    protected $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * Install the core Afterburner template.
     */
    public function install(string $name, string $directory): void
    {
        $this->output->writeln('<comment>Creating Laravel project with Afterburner template...</comment>');

        // Use composer create-project to clone the template
        $process = new Process([
            'composer',
            'create-project',
            'laravel-afterburner/jetstream',
            $directory,
            '--prefer-dist',
            '--no-interaction',
        ]);

        $process->setTimeout(600); // 10 minutes timeout
        $process->run(function ($type, $line) {
            $this->output->write($line);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to create project: ' . $process->getErrorOutput());
        }

        $this->output->writeln('');
        $this->output->writeln('<comment>Core template installed successfully!</comment>');
    }
}

