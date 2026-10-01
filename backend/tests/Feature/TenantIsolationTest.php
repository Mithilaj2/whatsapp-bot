<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshesTenantDatabase;
use Tests\TestCase;

/**
 * The plan's "automated cross-tenant tests in CI": one business must never
 * read or change another's data, through the API or directly in SQL.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshesTenantDatabase;

    private User $alice;

    private User $bob;

    private Tenant $aliceCo;

    private Tenant $bobCo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['password' => 'correct-horse-battery']);
        $this->bob = User::factory()->create(['password' => 'correct-horse-battery']);
        $this->aliceCo = app(TenantProvisioner::class)->create($this->alice, ['name' => 'Alice Co']);
        $this->bobCo = app(TenantProvisioner::class)->create($this->bob, ['name' => 'Bob Co']);
    }

    public function test_a_user_cannot_act_in_a_business_they_do_not_belong_to(): void
    {
        $token = $this->tokenFor($this->alice);

        $this->asUser($token, $this->bobCo->id)->getJson('/api/tenant')->assertNotFound();
        $this->asUser($token, $this->bobCo->id)->getJson('/api/teams')->assertNotFound();
        $this->asUser($token, $this->aliceCo->id)->getJson('/api/tenant')
            ->assertOk()->assertJsonPath('tenant.name', 'Alice Co');
    }

    public function test_tenant_routes_need_a_business_header(): void
    {
        $this->asUser($this->tokenFor($this->alice))->getJson('/api/teams')->assertStatus(400);
    }

    public function test_teams_are_visible_only_inside_their_business(): void
    {
        $alice = $this->tokenFor($this->alice);
        $bob = $this->tokenFor($this->bob);

        $teamId = $this->asUser($alice, $this->aliceCo->id)
            ->postJson('/api/teams', ['name' => 'Support'])
            ->assertCreated()->json('team.id');

        $this->asUser($bob, $this->bobCo->id)->getJson('/api/teams')
            ->assertOk()->assertJsonCount(0, 'teams');
        $this->asUser($bob, $this->bobCo->id)->deleteJson("/api/teams/{$teamId}")->assertNotFound();
        $this->asUser($alice, $this->aliceCo->id)->getJson('/api/teams')
            ->assertOk()->assertJsonCount(1, 'teams');
        $this->asUser($alice, $this->aliceCo->id)->deleteJson("/api/teams/{$teamId}")->assertNoContent();
    }

    public function test_me_lists_only_the_users_own_businesses(): void
    {
        $this->asUser($this->tokenFor($this->alice))->getJson('/api/me')
            ->assertOk()
            ->assertJsonCount(1, 'tenants')
            ->assertJsonPath('tenants.0.id', $this->aliceCo->id);
    }

    public function test_the_database_hides_other_tenants_rows_even_without_a_where_clause(): void
    {
        $context = app(TenantContext::class);
        $context->run($this->aliceCo->id, fn () => Team::create(['name' => 'Alice team']));
        $context->run($this->bobCo->id, fn () => Team::create(['name' => 'Bob team']));

        // No tenant set: nothing is visible.
        $this->assertSame(0, DB::table('teams')->count());
        $this->assertSame(0, DB::table('tenants')->count());

        // Raw SQL, no tenant filter: still only the current tenant's rows.
        $names = $context->run($this->aliceCo->id, fn () => DB::select('SELECT name FROM teams'));
        $this->assertSame(['Alice team'], array_column($names, 'name'));
    }

    public function test_the_database_rejects_writing_a_row_into_another_tenant(): void
    {
        $insert = fn (string $tenantId) => DB::table('teams')->insert([
            'id' => (string) str()->uuid7(),
            'tenant_id' => $tenantId,
            'name' => 'Team '.$tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $context = app(TenantContext::class);

        $this->assertTrue($context->run($this->aliceCo->id, fn () => $insert($this->aliceCo->id)));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');
        $context->run($this->aliceCo->id, fn () => $insert($this->bobCo->id));
    }

    public function test_the_app_role_cannot_bypass_row_level_security(): void
    {
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_audit_logs_are_append_only(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied');

        app(TenantContext::class)->run($this->aliceCo->id, fn () => DB::table('audit_logs')->update(['action' => 'tampered']));
    }

    public function test_an_agent_cannot_change_business_settings(): void
    {
        $agent = User::factory()->create(['password' => 'correct-horse-battery']);
        app(TenantContext::class)->run($this->aliceCo->id, fn () => TenantMember::create([
            'user_id' => $agent->id,
            'role' => TenantRole::Agent,
        ]));

        $this->asUser($this->tokenFor($agent), $this->aliceCo->id)
            ->patchJson('/api/tenant', ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_an_admin_cannot_make_someone_an_owner(): void
    {
        $admin = User::factory()->create(['password' => 'correct-horse-battery']);
        $agent = User::factory()->create();
        $agentMember = app(TenantContext::class)->run($this->aliceCo->id, function () use ($admin, $agent) {
            TenantMember::create(['user_id' => $admin->id, 'role' => TenantRole::Admin]);

            return TenantMember::create(['user_id' => $agent->id, 'role' => TenantRole::Agent]);
        });

        $this->asUser($this->tokenFor($admin), $this->aliceCo->id)
            ->patchJson("/api/members/{$agentMember->id}", ['role' => 'owner'])
            ->assertStatus(422);
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $owner = app(TenantContext::class)->run($this->aliceCo->id, fn () => TenantMember::where('user_id', $this->alice->id)->first());

        $this->asUser($this->tokenFor($this->alice), $this->aliceCo->id)
            ->patchJson("/api/members/{$owner->id}", ['role' => 'admin'])
            ->assertStatus(422);
    }
}
