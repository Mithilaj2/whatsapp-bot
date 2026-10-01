<?php

namespace Tests\Unit;

use App\Enums\TenantRole;
use PHPUnit\Framework\TestCase;

class TenantRoleTest extends TestCase
{
    public function test_only_owners_can_grant_the_owner_role(): void
    {
        $this->assertContains(TenantRole::Owner, TenantRole::Owner->assignableRoles());
        $this->assertNotContains(TenantRole::Owner, TenantRole::Admin->assignableRoles());
        $this->assertSame([], TenantRole::Agent->assignableRoles());
    }

    public function test_who_can_manage_the_business_and_teams(): void
    {
        $this->assertTrue(TenantRole::Admin->canManageTenant());
        $this->assertFalse(TenantRole::Supervisor->canManageTenant());
        $this->assertTrue(TenantRole::Supervisor->canManageTeams());
        $this->assertFalse(TenantRole::Viewer->canManageTeams());
    }
}
