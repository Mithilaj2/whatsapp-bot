<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps a Meta id (WABA or phone-number id) to the tenant that owns it, so
 * webhooks can be routed before any tenant is set.
 */
class MetaRoute extends Model
{
    public const WABA = 'waba';

    public const PHONE_NUMBER = 'phone_number';

    public $incrementing = false;

    protected $primaryKey = 'meta_id';

    protected $keyType = 'string';

    protected $fillable = ['kind', 'meta_id', 'tenant_id'];

    public static function tenantFor(string $kind, string $metaId): ?string
    {
        return static::where('kind', $kind)->where('meta_id', $metaId)->value('tenant_id');
    }
}
