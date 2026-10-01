<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant, HasUuids;

    public const IN = 'in';

    public const OUT = 'out';

    /**
     * Delivery statuses in the order they can happen. Webhooks may arrive out
     * of order, so a status only ever moves forward; "failed" is recorded
     * unless the message was already read.
     */
    public const STATUS_RANK = [
        'queued' => 0,
        'accepted' => 1,
        'sent' => 2,
        'delivered' => 3,
        'read' => 4,
    ];

    protected $fillable = [
        'conversation_id',
        'direction',
        'wamid',
        'type',
        'content_json',
        'media_key',
        'status',
        'status_at',
        'error_code',
        'error_title',
        'pricing_category',
        'billable',
        'sender_type',
        'sender_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'billable' => 'boolean',
            'status_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function canMoveTo(string $status): bool
    {
        if ($this->status === $status) {
            return false;
        }
        if ($status === 'failed') {
            return $this->status !== 'read';
        }
        if ($this->status === 'failed') {
            return false;
        }

        return (self::STATUS_RANK[$status] ?? -1) > (self::STATUS_RANK[$this->status] ?? -1);
    }
}
