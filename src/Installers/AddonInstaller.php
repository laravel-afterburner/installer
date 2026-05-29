<?php

namespace Afterburner\Installer\Installers;

use Afterburner\Installer\Support\PackageRegistry;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class AddonInstaller
{
    public function __construct(
        protected OutputInterface $output,
    ) {}

    /**
     * @param  list<string>  $selectedKeys
     * @return list<string> Installed package keys in order
     */
    public function install(string $directory, array $selectedKeys): array
    {
        $packages = PackageRegistry::available();
        $order = PackageRegistry::resolveInstallOrder($selectedKeys);
        $installed = [];

        foreach ($order as $key) {
            if (! isset($packages[$key]) || ! in_array($key, $selectedKeys, true)) {
                continue;
            }

            $package = $packages[$key];
            $this->output->writeln('');
            $this->output->writeln("<comment>Installing {$package['label']}...</comment>");

            $require = "{$package['composer']}:{$package['version']}";
            $process = new Process(
                ['composer', 'require', $require, '--no-interaction', '--no-progress'],
                $directory
            );
            $process->setTimeout(600);
            $process->run(function ($type, $line) {
                $this->output->write($line);
            });

            if (! $process->isSuccessful()) {
                throw new \RuntimeException("Failed to require {$package['composer']}: ".$process->getErrorOutput());
            }

            $installProcess = new Process(
                ['php', 'artisan', $package['install_command'], '--no-interaction'],
                $directory
            );
            $installProcess->setTimeout(600);
            $installProcess->run(function ($type, $line) {
                $this->output->write($line);
            });

            if (! $installProcess->isSuccessful()) {
                throw new \RuntimeException("Failed to run {$package['install_command']}: ".$installProcess->getErrorOutput());
            }

            $installed[] = $key;
            $this->output->writeln("<info>{$package['label']} installed.</info>");
        }

        return $installed;
    }
}
