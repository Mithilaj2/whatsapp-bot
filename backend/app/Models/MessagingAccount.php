<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessagingAccount extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'waba_id',
        'name',
        'currency',
        'timezone',
        'token_ciphertext',
        'token_key_id',
        'token_invalid_at',
        'subscribed_at',
        'status',
    ];

    protected $hidden = ['token_ciphertext', 'token_key_id'];

    protected function casts(): array
    {
        return [
            'token_invalid_at' => 'datetime',
            'subscribed_at' => 'datetime',
        ];
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }
}
