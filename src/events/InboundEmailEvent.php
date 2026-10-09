<?php

namespace justinholtweb\pigeon\events;

use craft\elements\User;
use craft\events\CancelableEvent;
use justinholtweb\pigeon\elements\Thread;
use justinholtweb\pigeon\mail\InboundMessage;
use justinholtweb\pigeon\records\MessageRecord;
use justinholtweb\pigeon\records\ParticipantRecord;

/**
 * Fired around the processing of one received email.
 *
 * Before: the email has passed the loop guard, been matched to `thread` and its sender checked —
 * `participant` and/or `user` say who it will be posted as. Set `isValid` to false to drop it (it is
 * recorded as ignored), or change `email` — fewer attachments, different text. After: `message` is
 * the posted reply, when there is one, and `outcome` says what became of the email.
 */
class InboundEmailEvent extends CancelableEvent
{
    public ?InboundMessage $email = null;

    /** The `pigeon_inbound` row. */
    public ?int $inboundId = null;

    public ?Thread $thread = null;

    /** The participant it is posted as — null for staff answering a support alert. Before only. */
    public ?ParticipantRecord $participant = null;

    /** The account it is posted as — null for a guest. Before only. */
    public ?User $user = null;

    /** The reply that was posted. After only. */
    public ?MessageRecord $message = null;

    /** `reply`, `ignored`, `rejected` — after only. */
    public ?string $outcome = null;
}
