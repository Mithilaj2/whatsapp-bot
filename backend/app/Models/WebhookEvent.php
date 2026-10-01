<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'received_at',
        'object',
        'waba_id',
        'payload',
        'signature_ok',
        'processed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_ok' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
