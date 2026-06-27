<?php

namespace justinholtweb\pigeon\enums;

enum ThreadStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Pending => 'Pending',
            self::Closed => 'Closed',
        };
    }

    /**
     * Craft status indicator color.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'green',
            self::Pending => 'orange',
            self::Closed => 'grey',
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
