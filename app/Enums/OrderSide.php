<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderSide: string
{
    use EnumSupport;

    case BUY = 'buy';
    case SELL = 'sell';

    /**
     * Determines if the current instance represents a buying action.
     *
     * @return bool Returns true if the instance matches the BUY constant; otherwise, false.
     */
    public function isBuying(): bool
    {
        return $this === self::BUY;
    }

    /**
     * Determines if the current instance represents a selling action.
     *
     * @return bool Returns true if the instance matches the SELL constant; otherwise, false.
     */
    public function isSelling(): bool
    {
        return $this === self::SELL;
    }
}
