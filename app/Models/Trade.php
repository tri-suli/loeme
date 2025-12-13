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
        'fee_amount',
        'fee_currency',
        'fee_payer',
        'executed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'symbol' => Crypto::class,
            'price'  => 'decimal:18',
            'amount' => 'decimal:18',
            // Fees are in quote currency (USD) and stored/displayed to 2 decimals
            'fee_amount'  => 'decimal:2',
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
