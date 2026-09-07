<?php

namespace App\Console\Commands;

use App\Services\Support\SupportNotificationService;
use Illuminate\Console\Command;

class SupportProcessEventsCommand extends Command
{
    protected $signature = 'support:process-events {--batch=100} {--recipients=250}';

    protected $description = 'Project one bounded page of committed support events into authorized in-app notifications.';

    public function handle(SupportNotificationService $notifications): int
    {
        foreach (['batch', 'recipients'] as $option) {
            if (! ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1) {
                $this->error('Los límites deben ser enteros positivos.');

                return self::INVALID;
            }
        }
        $result = $notifications->publish((int) $this->option('batch'), (int) $this->option('recipients'));
        $this->info('SUPPORT_EVENTS_PROCESSED: '.$result['events'].'; notifications: '.$result['notifications']);

        return self::SUCCESS;
    }
}
