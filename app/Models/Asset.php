<?php

namespace App\Models;

use App\Enums\Crypto;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'symbol',
        'amount',
        'locked_amount',
    ];

    /**
     * Get the owning user.
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
            'symbol'        => Crypto::class,
            'amount'        => 'decimal:18',
            'locked_amount' => 'decimal:18',
        ];
    }

    /**
     * Normalize locked_amount representation: return plain '0' when zero
     * to align with tests and UI expectations; otherwise keep 18-decimal string.
     */
    protected function lockedAmount(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $str = (string) $value;
                // Normalize numeric zero like "0.000000..." → "0" without relying on bcmath
                $normalized = rtrim($str, '0');
                $normalized = rtrim($normalized, '.');
                if ($normalized === '' || $normalized === '0') {
                    return '0';
                }

                return $str;
            }
        );
    }
}
