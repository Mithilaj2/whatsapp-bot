<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: the app's database role cannot update or delete these rows.
 */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before_json',
        'after_json',
        'ip_address',
        'at',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'at' => 'datetime',
        ];
    }
}
