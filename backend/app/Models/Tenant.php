<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'gstin',
        'timezone',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(TenantMember::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
