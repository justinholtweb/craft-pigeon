<?php

namespace justinholtweb\pigeon\enums;

enum ThreadType: string
{
    /** Guest or customer ↔ admin/staff (support inbox). */
    case Support = 'support';

    /** Craft user ↔ Craft user (direct message). */
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Support',
            self::Direct => 'Direct',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
