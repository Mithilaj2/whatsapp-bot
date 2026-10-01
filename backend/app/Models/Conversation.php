<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'contact_id',
        'phone_number_id',
        'status',
        'owner_type',
        'assignee_id',
        'team_id',
        'priority',
        'csw_expires_at',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'csw_expires_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Inside the 24-hour customer service window, any message type may be sent. */
    public function windowIsOpen(): bool
    {
        return $this->csw_expires_at !== null && $this->csw_expires_at->isFuture();
    }
}
