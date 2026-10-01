<?php

namespace App\Enums;

/**
 * Roles a user can hold inside one business (tenant). Platform roles such as
 * super admin are separate and live on the user.
 */
enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Agent = 'agent';
    case Viewer = 'viewer';
    case Developer = 'developer';

    /** Can change business settings, members and roles. */
    public function canManageTenant(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** Can manage teams and assignments. */
    public function canManageTeams(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Supervisor], true);
    }

    /** Roles this role may grant to someone else. Only an owner can make owners. */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => self::cases(),
            self::Admin => array_values(array_filter(self::cases(), fn (self $r) => $r !== self::Owner)),
            default => [],
        };
    }
}
