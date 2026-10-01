<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'bsuid',
        'parent_bsuid',
        'wa_phone',
        'name',
        'username',
        'attributes_json',
        'language',
        'last_inbound_at',
    ];

    protected function casts(): array
    {
        return [
            'attributes_json' => 'array',
            'last_inbound_at' => 'datetime',
        ];
    }

    /**
     * Can we address this contact? Sending by BSUID alone is not built yet:
     * Meta's request field for it needs checking against their docs first.
     */
    public function isReachable(): bool
    {
        return $this->wa_phone !== null;
    }
}
