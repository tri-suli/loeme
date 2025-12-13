<?php

namespace App\Models;

use App\Enums\Crypto;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'symbol',
        'side',
        'price',
        'amount',
        'remaining',
        'status',
    ];

    /**
     * Define an inverse one-to-one or many relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'symbol'    => Crypto::class,
            'side'      => OrderSide::class,
            'status'    => OrderStatus::class,
            'price'     => 'decimal:18',
            'amount'    => 'decimal:18',
            'remaining' => 'decimal:18',
        ];
    }
}
