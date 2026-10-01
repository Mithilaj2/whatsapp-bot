<?php

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Raw webhook payloads are kept 30 days (plan, section 13: data retention).
Artisan::command('webhooks:prune {--days=30}', function () {
    $deleted = WebhookEvent::where('received_at', '<', now()->subDays((int) $this->option('days')))->delete();
    $this->info("Deleted {$deleted} webhook events.");
})->purpose('Delete raw webhook payloads past their retention period');

Schedule::command('webhooks:prune')->daily();
