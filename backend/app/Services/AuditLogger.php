<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function __construct(private TenantContext $context) {}

    public function record(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?string $actorId = null,
    ): void {
        AuditLog::create([
            'tenant_id' => $this->context->tenantId(),
            'actor_id' => $actorId ?? $this->context->userId(),
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => request()?->ip(),
            'at' => now(),
        ]);
    }
}
