<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCancelled implements ShouldBroadcast
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
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $userId = (int) ($this->payload['user_id'] ?? 0);
        $symbol = (string) ($this->payload['symbol'] ?? '');

        return [
            // Legacy user channel
            new PrivateChannel('private-user.' . $userId),
            // Portfolio updates per user
            new PrivateChannel('portfolio.' . $userId),
            // Order book updates per symbol
            new PrivateChannel('orderbook.' . strtolower($symbol)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrderCancelled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
