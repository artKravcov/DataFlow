<?php

namespace Tests\Feature;

use App\Models\FileAsset;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_an_idempotent_ready_to_run_demo_workflow(): void
    {
        config()->set('dataflow.demo_admin_email', 'admin@example.test');
        config()->set('dataflow.demo_password', 'demo-password-123');

        $this->seed(DatabaseSeeder::class);

        $organization = Organization::where('name', 'DataFlow Demo Organization')->firstOrFail();
        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $asset = FileAsset::where('organization_id', $organization->id)->where('storage_key', 'assets/demo-paid-orders-v1.csv')->firstOrFail();
        $pipeline = Pipeline::where('organization_id', $organization->id)->where('name', 'Paid orders')->firstOrFail();
        self::assertSame("order_id,status,total\n101,paid,12.50\n102,pending,8.00\n103,paid,20.00\n", Storage::disk('local')->get($asset->storage_key));
        self::assertSame(1, $pipeline->revision);
        self::assertSame(4, $pipeline->nodes()->count());
        self::assertSame(3, $pipeline->edges()->count());
        self::assertSame((string) $asset->id, $pipeline->nodes()->where('node_id', 'source')->value('config')['asset_id']);
        self::assertTrue(User::where('email', 'demo-viewer@dataflow.local')->firstOrFail()->memberships()->where('organization_id', $organization->id)->exists());
        self::assertSame(2, Organization::count());
        self::assertSame(2, Membership::query()->where('user_id', $admin->id)->where('role', 'owner')->count());
        foreach (['owner', 'editor', 'viewer'] as $role) {
            $user = User::where('email', "demo-$role@dataflow.local")->firstOrFail();
            $this->assertDatabaseHas('organization_memberships', ['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role]);
        }

        $this->seed(DatabaseSeeder::class);

        self::assertSame(1, Pipeline::where('organization_id', $organization->id)->where('name', 'Paid orders')->count());
        self::assertSame(4, $pipeline->fresh()->nodes()->count());
        self::assertSame(3, $pipeline->fresh()->edges()->count());
    }
}
