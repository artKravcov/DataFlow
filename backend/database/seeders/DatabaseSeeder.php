<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $adminEmail = config('dataflow.demo_admin_email');
        $password = config('dataflow.demo_password');

        if (! is_string($adminEmail) || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set DATAFLOW_DEMO_ADMIN_EMAIL in the local .env before seeding.');
        }

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set a local DATAFLOW_DEMO_PASSWORD of at least 12 characters before seeding.');
        }

        DB::transaction(function () use ($adminEmail, $password): void {
            Organization::firstOrCreate(['name' => 'DataFlow Demo Organization']);
            Organization::firstOrCreate(['name' => 'DataFlow Sandbox Organization']);

            $organizations = Organization::query()->get();

            $admin = $this->firstOrCreateUser($adminEmail, 'Art Kravcov', $password);
            $roleUsers = [
                'owner' => $this->firstOrCreateUser('demo-owner@dataflow.local', 'Demo Owner', $password),
                'editor' => $this->firstOrCreateUser('demo-editor@dataflow.local', 'Demo Editor', $password),
                'viewer' => $this->firstOrCreateUser('demo-viewer@dataflow.local', 'Demo Viewer', $password),
            ];

            foreach ($organizations as $organization) {
                $this->assignRole($organization, $admin, 'owner');
            }

            $roleOrganization = $organizations->first();

            foreach ($roleUsers as $role => $user) {
                $this->assignRole($roleOrganization, $user, $role);
            }
        });
    }

    private function firstOrCreateUser(string $email, string $name, string $password): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );
    }

    private function assignRole(Organization $organization, User $user, string $role): void
    {
        Membership::updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $user->id],
            ['role' => $role],
        );
    }
}