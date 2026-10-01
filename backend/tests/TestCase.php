<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign in through the real API and return headers for later requests,
     * so tests exercise the same token and tenant checks as the app.
     */
    protected function tokenFor(User $user, string $password = 'correct-horse-battery'): string
    {
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password]);
        $response->assertOk();
        $this->resetAuth();

        return $response->json('access_token');
    }

    protected function asUser(string $token, ?string $tenantId = null): static
    {
        $this->resetAuth();
        $headers = ['Authorization' => 'Bearer '.$token];
        if ($tenantId !== null) {
            $headers['X-Tenant-Id'] = $tenantId;
        }

        return $this->withHeaders($headers);
    }

    /** Laravel caches the resolved user between test requests; forget it. */
    protected function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
    }
}
