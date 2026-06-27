<?php

namespace justinholtweb\pigeon\enums;

enum ParticipantRole: string
{
    /** The party who started the thread. */
    case Owner = 'owner';

    /** A staff member handling a support thread. */
    case Admin = 'admin';

    /** A guest (no Craft account), identified by email + token. */
    case Guest = 'guest';

    /** A regular participant (Craft user). */
    case Participant = 'participant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Guest => 'Guest',
            self::Participant => 'Participant',
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
