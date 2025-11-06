<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entityLabel = env('AFTERBURNER_ENTITY_LABEL', 'team');

        $roles = $this->getRolesForEntityType($entityLabel);

        foreach ($roles as $role) {
            DB::table('roles')->insertOrIgnore([
                'name' => $role['name'],
                'guard_name' => $role['guard_name'] ?? 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info("Seeded roles for entity type: {$entityLabel}");
    }

    /**
     * Get roles based on entity type.
     */
    protected function getRolesForEntityType(string $entityType): array
    {
        return match ($entityType) {
            'team' => $this->getTeamRoles(),
            'strata' => $this->getStrataRoles(),
            'company' => $this->getCompanyRoles(),
            'organization' => $this->getOrganizationRoles(),
            default => $this->getTeamRoles(), // Default fallback
        };
    }

    /**
     * Get roles for Team entity type.
     */
    protected function getTeamRoles(): array
    {
        return [
            ['name' => 'Team Owner', 'guard_name' => 'web'],
            ['name' => 'Team Member', 'guard_name' => 'web'],
            ['name' => 'Team Admin', 'guard_name' => 'web'],
        ];
    }

    /**
     * Get roles for Strata entity type.
     */
    protected function getStrataRoles(): array
    {
        return [
            ['name' => 'Strata President', 'guard_name' => 'web'],
            ['name' => 'Strata Council Member', 'guard_name' => 'web'],
            ['name' => 'Strata Owner', 'guard_name' => 'web'],
            ['name' => 'Strata Manager', 'guard_name' => 'web'],
        ];
    }

    /**
     * Get roles for Company entity type.
     */
    protected function getCompanyRoles(): array
    {
        return [
            ['name' => 'Company Owner', 'guard_name' => 'web'],
            ['name' => 'Company Admin', 'guard_name' => 'web'],
            ['name' => 'Company Manager', 'guard_name' => 'web'],
            ['name' => 'Company Employee', 'guard_name' => 'web'],
        ];
    }

    /**
     * Get roles for Organization entity type.
     */
    protected function getOrganizationRoles(): array
    {
        return [
            ['name' => 'Organization Director', 'guard_name' => 'web'],
            ['name' => 'Organization Manager', 'guard_name' => 'web'],
            ['name' => 'Organization Member', 'guard_name' => 'web'],
            ['name' => 'Organization Staff', 'guard_name' => 'web'],
        ];
    }
}




