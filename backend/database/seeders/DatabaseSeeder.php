<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

            $roleOrganization = Organization::where('name', 'DataFlow Demo Organization')->firstOrFail();

            foreach ($roleUsers as $role => $user) {
                $this->assignRole($roleOrganization, $user, $role);
            }

            $this->createDemoWorkflow($roleOrganization);
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

    private function createDemoWorkflow(Organization $organization): void
    {
        $contents = file_get_contents(base_path('../demo/paid-orders.csv'));
        if ($contents === false) {
            throw new RuntimeException('Demo CSV is missing.');
        }
        $disk = Storage::disk('local');
        $key = 'assets/demo-paid-orders-v1.csv';
        $digest = hash('sha256', $contents);
        if ($disk->exists($key) && hash('sha256', $disk->get($key)) !== $digest) {
            throw new RuntimeException('Demo CSV storage key has unexpected contents.');
        }
        if (! $disk->exists($key)) {
            $disk->makeDirectory('assets');
            $disk->put($key, $contents);
        }
        $asset = \App\Models\FileAsset::firstOrCreate(
            ['storage_key' => $key],
            ['organization_id' => $organization->id, 'original_name' => 'paid-orders.csv', 'size_bytes' => strlen($contents), 'sha256' => $digest, 'state' => 'ready'],
        );
        if ($asset->organization_id !== $organization->id || $asset->sha256 !== $digest || $asset->state !== 'ready') {
            throw new RuntimeException('Demo CSV asset has unexpected metadata.');
        }
        $project = $organization->projects()->firstOrCreate(
            ['name' => 'Демонстрация DataFlow'],
            ['description' => 'CSV → Filter → Map → CSV'],
        );
        $pipeline = $organization->pipelines()->firstOrCreate(
            ['project_id' => $project->id, 'name' => 'Платные заказы'],
            ['revision' => 0, 'schema_version' => 1],
        );
        if ($pipeline->nodes()->exists() || $pipeline->edges()->exists()) {
            return;
        }
        $nodes = [
            ['node_id' => 'source', 'type' => 'csv.source', 'type_version' => 1, 'position' => ['x' => 0, 'y' => 0], 'config' => ['asset_id' => (string) $asset->id, 'delimiter' => ',', 'header' => true], 'schema_hints' => [['name' => 'order_id', 'type' => 'string', 'nullable' => false], ['name' => 'status', 'type' => 'string', 'nullable' => false], ['name' => 'total', 'type' => 'string', 'nullable' => false]]],
            ['node_id' => 'filter', 'type' => 'transform.filter', 'type_version' => 1, 'position' => ['x' => 240, 'y' => 0], 'config' => ['field' => 'status', 'operator' => 'equals', 'value' => 'paid']],
            ['node_id' => 'map', 'type' => 'transform.map', 'type_version' => 1, 'position' => ['x' => 480, 'y' => 0], 'config' => ['mappings' => [['source' => 'order_id', 'target' => 'id'], ['source' => 'total', 'target' => 'amount']]]],
            ['node_id' => 'output', 'type' => 'csv.destination', 'type_version' => 1, 'position' => ['x' => 720, 'y' => 0], 'config' => ['filename' => 'paid-orders.csv']],
        ];
        foreach ($nodes as $node) {
            $pipeline->nodes()->create($node);
        }
        foreach ([['e1', 'source', 'filter'], ['e2', 'filter', 'map'], ['e3', 'map', 'output']] as [$edge, $source, $target]) {
            $pipeline->edges()->create(['edge_id' => $edge, 'source_node_id' => $source, 'source_port' => 'records', 'target_node_id' => $target, 'target_port' => 'records']);
        }
        $pipeline->revision = 1;
        $pipeline->save();
    }
}
