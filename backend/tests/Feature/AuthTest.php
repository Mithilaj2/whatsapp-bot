<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\RefreshesTenantDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshesTenantDatabase;

    public function test_signup_creates_the_user_and_a_business_they_own(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Asha',
            'email' => 'Asha@Example.com',
            'password' => 'correct-horse-battery',
            'business_name' => 'ABC Traders',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['access_token', 'expires_at', 'tenant_id', 'user' => ['id', 'email']])
            ->assertCookie(config('auth.refresh_cookie'));

        $me = $this->asUser($response->json('access_token'))->getJson('/api/me');
        $me->assertOk()
            ->assertJsonPath('user.email', 'asha@example.com')
            ->assertJsonPath('tenants.0.name', 'ABC Traders')
            ->assertJsonPath('tenants.0.role', 'owner');
    }

    public function test_passwords_are_hashed_with_argon2id(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->assertStringStartsWith('$argon2id$', $user->fresh()->password);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422);
    }

    public function test_refresh_rotates_the_token_and_reuse_revokes_the_session(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $first = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
            'refresh_token_in_body' => true,
        ])->json('refresh_token');

        $second = $this->postJson('/api/auth/refresh', ['refresh_token' => $first, 'refresh_token_in_body' => true]);
        $second->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
        $this->assertNotSame($first, $second->json('refresh_token'));

        // The old token is replayed: treat as stolen and end the whole session.
        $this->postJson('/api/auth/refresh', ['refresh_token' => $first])->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $second->json('refresh_token')])->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_logout_revokes_the_access_token(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $token = $this->tokenFor($user);

        $this->asUser($token)->postJson('/api/auth/logout')->assertOk();
        $this->asUser($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_access_tokens_expire(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $token = $this->tokenFor($user);

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->asUser($token)->getJson('/api/me')->assertUnauthorized();
    }
}
