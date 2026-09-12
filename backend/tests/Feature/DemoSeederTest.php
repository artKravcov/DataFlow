<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_assigns_admin_and_each_project_role(): void
    {
        config()->set('dataflow.demo_admin_email', 'admin@example.test');
        config()->set('dataflow.demo_password', 'local-test-password');

        $this->seed();

        $roleOrganization = Organization::where('name', 'DataFlow Demo Organization')->firstOrFail();
        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertSame(2, Organization::count());
        $this->assertSame(2, Membership::query()->where('user_id', $admin->id)->where('role', 'owner')->count());
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $roleOrganization->id,
            'user_id' => User::where('email', 'demo-owner@dataflow.local')->firstOrFail()->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $roleOrganization->id,
            'user_id' => User::where('email', 'demo-editor@dataflow.local')->firstOrFail()->id,
            'role' => 'editor',
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $roleOrganization->id,
            'user_id' => User::where('email', 'demo-viewer@dataflow.local')->firstOrFail()->id,
            'role' => 'viewer',
        ]);
    }
}
