<?php

namespace App\Models;

use App\Enums\TenantRole;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMember extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'team_id',
        'max_chats',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'max_chats' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
