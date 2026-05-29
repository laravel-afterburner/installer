<?php

namespace Afterburner\Installer\Support;

/**
 * Registry of installable Afterburner add-on packages.
 */
class PackageRegistry
{
    /**
     * @return array<string, array{
     *     label: string,
     *     composer: string,
     *     version: string,
     *     install_command: string,
     *     description: string,
     *     requires: list<string>,
     *     available: bool
     * }>
     */
    public static function all(): array
    {
        return [
            'documents' => [
                'label' => 'Documents',
                'composer' => 'laravel-afterburner/documents',
                'version' => '^1.0',
                'install_command' => 'afterburner:documents:install',
                'description' => 'Team document library with folders, uploads, and retention tags',
                'requires' => [],
                'available' => true,
            ],
            'communications' => [
                'label' => 'Communications',
                'composer' => 'laravel-afterburner/communications',
                'version' => '^1.0',
                'install_command' => 'afterburner:communications:install',
                'description' => 'Team announcements, discussion threads, and communication log',
                'requires' => [],
                'available' => true,
            ],
            'voting' => [
                'label' => 'Voting',
                'composer' => 'laravel-afterburner/voting',
                'version' => '^1.0',
                'install_command' => 'afterburner:voting:install',
                'description' => 'Team-scoped ballots, vote casting, quorum, and proxy support',
                'requires' => [],
                'available' => true,
            ],
            'meetings' => [
                'label' => 'Meetings',
                'composer' => 'laravel-afterburner/meetings',
                'version' => '^1.0',
                'install_command' => 'afterburner:meetings:install',
                'description' => 'AGM and council meetings with attendance and optional ballot links',
                'requires' => [],
                'available' => true,
            ],
            'playbook' => [
                'label' => 'Playbook',
                'composer' => 'laravel-afterburner/playbook',
                'version' => '^1.0',
                'install_command' => 'afterburner:playbook:install',
                'description' => 'In-app documentation with platform guides and package-specific sections',
                'requires' => [],
                'available' => true,
            ],
            'subscriptions' => [
                'label' => 'Subscriptions',
                'composer' => 'laravel-afterburner/subscriptions',
                'version' => '^1.0',
                'install_command' => 'afterburner:subscriptions:install',
                'description' => 'Team-scoped Stripe subscription billing with trials and hard-block enforcement',
                'requires' => [],
                'available' => true,
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     composer: string,
     *     version: string,
     *     install_command: string,
     *     description: string,
     *     requires: list<string>,
     *     available: bool
     * }>
     */
    public static function available(): array
    {
        return array_filter(static::all(), fn (array $package): bool => $package['available']);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function resolveInstallOrder(array $keys): array
    {
        $packages = static::all();
        $resolved = [];
        $pending = array_values(array_unique($keys));

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $index => $key) {
                if (! isset($packages[$key])) {
                    unset($pending[$index]);
                    $pending = array_values($pending);

                    continue;
                }

                $requires = $packages[$key]['requires'];
                $unmet = array_filter(
                    $requires,
                    fn (string $required): bool => in_array($required, $keys, true) && ! in_array($required, $resolved, true)
                );

                if ($unmet !== []) {
                    continue;
                }

                $resolved[] = $key;
                unset($pending[$index]);
                $pending = array_values($pending);
                $progress = true;
            }

            if (! $progress) {
                break;
            }
        }

        foreach ($pending as $key) {
            if (! in_array($key, $resolved, true)) {
                $resolved[] = $key;
            }
        }

        return $resolved;
    }
}
