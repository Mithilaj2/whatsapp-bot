<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'business_hours_id'];

    public function members(): HasMany
    {
        return $this->hasMany(TenantMember::class);
    }
}
