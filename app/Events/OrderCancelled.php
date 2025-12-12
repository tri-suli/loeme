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
            new PrivateChannel('private-user.' . $userId),
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
