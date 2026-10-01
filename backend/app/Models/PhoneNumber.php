<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneNumber extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'messaging_account_id',
        'phone_number_id',
        'whatsapp_account_id',
        'display_number',
        'display_name',
        'quality',
        'messaging_limit',
        'is_coexistence',
        'status',
    ];

    protected $hidden = ['pin_ciphertext'];

    protected function casts(): array
    {
        return ['is_coexistence' => 'boolean'];
    }

    public function messagingAccount(): BelongsTo
    {
        return $this->belongsTo(MessagingAccount::class);
    }
}
