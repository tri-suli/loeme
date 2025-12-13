<?php

namespace App\Enums;

use Illuminate\Support\Arr;

trait EnumSupport
{
    /**
     * Retrieves the names of all cases for the enumerated type.
     *
     * @return array The list of case names.
     */
    public static function names(): array
    {
        return Arr::map(self::cases(), fn (self $case) => $case->name);
    }

    /**
     * Retrieves the values of all cases for the enumerated type.
     *
     * @return array The list of case values.
     */
    public static function values(): array
    {
        return Arr::map(self::cases(), fn (self $case) => $case->value);
    }
}
