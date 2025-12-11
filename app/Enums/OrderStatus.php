<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: int
{
    use EnumSupport;

    case OPEN = 1;
    case FILLED = 2;
    case CANCELLED = 3;

    /**
     * Determines if the current order state is `CANCELED`.
     *
     * @return bool True if the state is canceled, false otherwise.
     */
    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    /**
     * Determines if the current order state is `FILLED`.
     *
     * @return bool True if the state is filled, false otherwise.
     */
    public function isFilled(): bool
    {
        return $this === self::FILLED;
    }

    /**
     * Determines if the current order state is `OPEN`.
     *
     * @return bool True if the state is open, false otherwise.
     */
    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * Converts the current value to its string representation.
     *
     * @return string The string representation of the current value.
     */
    public function toString(): string
    {
        return (string) $this->value;
    }
}
