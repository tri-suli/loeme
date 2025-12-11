<?php

declare(strict_types=1);

namespace App\Enums;

enum Crypto: string
{
    use EnumSupport;

    case BTC = 'btc';
    case ETH = 'eth';

    /**
     * Provides a description based on the current enum value.
     */
    public function description(): string
    {
        return match ($this) {
            self::BTC => 'Bitcoin',
            self::ETH => 'Ethereum',
        };
    }
}
