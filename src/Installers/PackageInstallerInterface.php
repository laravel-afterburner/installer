<?php

namespace Afterburner\Installer\Installers;

/**
 * Interface for package installers.
 * 
 * This interface will be used by add-on packages in the future
 * to provide custom installation logic.
 */
interface PackageInstallerInterface
{
    /**
     * Install the package into the given project directory.
     */
    public function install(string $projectDirectory): void;

    /**
     * Get the package name.
     */
    public function getName(): string;

    /**
     * Get the package description.
     */
    public function getDescription(): string;
}

