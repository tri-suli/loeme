<?php

namespace App\Models;

use App\Enums\Crypto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'trade_uid',
        'buy_order_id',
        'sell_order_id',
        'symbol',
        'price',
        'amount',
        'executed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'symbol'      => Crypto::class,
            'price'       => 'decimal:18',
            'amount'      => 'decimal:18',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Establishes a belongs-to relationship with the Order model
     * using the 'buy_order_id' foreign key.
     *
     * @return BelongsTo<Order>
     */
    public function buyOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'buy_order_id');
    }

    /**
     * Establishes a belongs-to relationship with the Order model
     * using the 'sell_order_id' foreign key.
     *
     * @return BelongsTo<Order>
     */
    public function sellOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'sell_order_id');
    }
}
