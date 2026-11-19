<?php

namespace App\Jobs;

use App\Models\NotificationCampaign;
use App\Services\Notifications\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param list<string> $userIds */
    public function __construct(public readonly string $campaignId, public readonly array $userIds, public readonly bool $finalize = false)
    {
        $this->onQueue('low');
        $this->afterCommit();
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaign = NotificationCampaign::query()->find($this->campaignId);
        if ($campaign === null) {
            return;
        }
        $this->finalize ? $campaigns->finalize($campaign) : $campaigns->deliverChunk($campaign, $this->userIds);
    }
}
