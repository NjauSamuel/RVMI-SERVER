<?php

namespace App\Console\Commands;

use App\Services\Push\ApprovalPushHold;
use Illuminate\Console\Command;

/**
 * Sends the approval count that piled up after the last instant push,
 * once 30 minutes have passed. Runs on the same five-minute schedule
 * as media retries, so it can land a few minutes late.
 */
class FlushApprovalPushCommand extends Command
{
    protected $signature = 'notifications:flush-approval-hold';

    protected $description = 'Send one push for approvals that waited out the 30-minute hold.';

    public function __construct(private readonly ApprovalPushHold $hold)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->hold->flush();
        $this->info('Approval hold flushed.');

        return self::SUCCESS;
    }
}
