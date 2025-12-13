<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderMatched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Ensure the event is only broadcast after DB commit.
     */
    public bool $afterCommit = true;

    /**
     * Queue name for broadcasting job.
     */
    public string $broadcastQueue = 'broadcasts';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $buyerId = (int) ($this->payload['buyer_id'] ?? 0);
        $sellerId = (int) ($this->payload['seller_id'] ?? 0);

        return [
            // Legacy per-user trading channel (kept for backward compatibility)
            new PrivateChannel('private-user.' . $buyerId),
            new PrivateChannel('private-user.' . $sellerId),
            // Portfolio-specific channel per user (preferred)
            new PrivateChannel('portfolio.' . $buyerId),
            new PrivateChannel('portfolio.' . $sellerId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrderMatched';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
