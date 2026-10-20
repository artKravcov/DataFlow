<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     */
    public function test_registration_creates_user_personal_organization_and_owner_membership(): void
    {
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfMiddleware::class);
        $csrfToken = 'test-csrf-token';

        $response = $this
            ->withSession(['_token' => $csrfToken])
            ->withHeader('Origin', 'http://localhost:8080')
            ->withHeader('Referer', 'http://localhost:8080/')
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/v1/auth/register', [
                'name' => 'Test',
                'email' => 'registration@example.test',
                'password' => '123451234512345',
                'password_confirmation' => '123451234512345',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.email', 'registration@example.test');

        $this->assertDatabaseHas('users', [
            'email' => 'registration@example.test',
        ]);

        $user = User::where('email', 'registration@example.test')->firstOrFail();

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->assertDatabaseCount('organizations', 1);
        $this->assertAuthenticatedAs($user);
    }

    public function test_same_origin_registration_requires_a_csrf_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfMiddleware::class);
        $response = $this
            ->withHeader('Origin', 'http://localhost:8080')
            ->withHeader('Referer', 'http://localhost:8080/')
            ->postJson('/api/v1/auth/register', [
                'name' => 'CSRF Test User',
                'email' => 'csrf@example.test',
                'password' => '123451234512345',
                'password_confirmation' => '123451234512345',
            ]);

        $response->assertStatus(419);
        $response->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
        $this->assertDatabaseMissing('users', [
            'email' => 'csrf@example.test',
        ]);
    }

    public function test_user_can_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.test',
            'password' => '123451234512345',
        ]);
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfMiddleware::class);
        $csrfToken = 'test-csrf-token';

        $response = $this
            ->withSession(['_token' => $csrfToken])
            ->withHeader('Origin', 'http://localhost:8080')
            ->withHeader('Referer', 'http://localhost:8080/')
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => '123451234512345',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_user_can_get_me(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonPath('data.organizations.0.id', $organization->id);
        $response->assertJsonPath('data.organizations.0.role', 'owner');
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfMiddleware::class);
        $csrfToken = 'test-csrf-token';

        $response = $this
            ->actingAs($user)
            ->withSession(['_token' => $csrfToken])
            ->withHeader('Origin', 'http://localhost:8080')
            ->withHeader('Referer', 'http://localhost:8080/')
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk();
        $response->assertJsonPath('data', null);
    }
}

class StrictCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
