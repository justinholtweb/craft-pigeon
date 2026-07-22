<?php

namespace justinholtweb\pigeontests\unit\services;

use craft\helpers\Db;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\pigeon\enums\ParticipantRole;
use justinholtweb\pigeon\records\MessageReadRecord;
use justinholtweb\pigeon\records\ParticipantRecord;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class ParticipantsServiceTest extends PigeonTestCase
{
    public function testEnsureUserIsIdempotent(): void
    {
        $thread = $this->createGuestThread();
        $user = $this->createUser('participant@example.test');

        $first = $this->plugin()->participants->ensureUser($thread->id, $user->id);
        $second = $this->plugin()->participants->ensureUser($thread->id, $user->id, ParticipantRole::Admin->value);

        self::assertSame($first->id, $second->id);
        self::assertCount(1, ParticipantRecord::findAll(['threadId' => $thread->id, 'userId' => $user->id]));
    }

    public function testEnsureUserCopiesUserDetails(): void
    {
        $thread = $this->createGuestThread();
        $user = $this->createUser('details@example.test');

        $participant = $this->plugin()->participants->ensureUser($thread->id, $user->id);

        self::assertSame($user->email, $participant->email);
        self::assertNotNull($participant->name);
        self::assertTrue((bool)$participant->notify);
    }

    public function testEnsureGuestNormalizesEmailAndIsIdempotent(): void
    {
        $thread = $this->createGuestThread();

        $first = $this->plugin()->participants->ensureGuest($thread->id, '  Someone@Example.test ', 'Someone');
        $second = $this->plugin()->participants->ensureGuest($thread->id, 'someone@example.test');

        self::assertSame('someone@example.test', $first->email);
        self::assertSame($first->id, $second->id);
    }

    public function testGetForThreadReturnsEveryParticipant(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();
        $this->plugin()->participants->ensureUser($thread->id, $staff->id, ParticipantRole::Admin->value);

        self::assertCount(2, $this->plugin()->participants->getForThread($thread->id));
    }

    public function testGetForUserReturnsNullWhenNotAParticipant(): void
    {
        $thread = $this->createGuestThread();
        $stranger = $this->createUser('stranger@example.test');

        self::assertNull($this->plugin()->participants->getForUser($thread->id, $stranger->id));
    }

    public function testMintTokenReturnsRawTokenAndStoresOnlyItsHash(): void
    {
        $thread = $this->createGuestThread();
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];

        $raw = $this->plugin()->participants->mintToken($participant);

        self::assertNotSame('', $raw);
        self::assertNotSame($raw, $participant->tokenHash, 'The raw token must never be stored');
        self::assertSame(hash('sha256', $raw), $participant->tokenHash);
        self::assertNotNull($participant->tokenExpiresAt);
    }

    public function testMintTokenRotatesTheToken(): void
    {
        $thread = $this->createGuestThread();
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];

        $first = $this->plugin()->participants->mintToken($participant);
        $second = $this->plugin()->participants->mintToken($participant);

        self::assertNotSame($first, $second);
        self::assertNull($this->plugin()->participants->findByToken($first), 'The old token should stop working');
        self::assertNotNull($this->plugin()->participants->findByToken($second));
    }

    public function testFindByTokenResolvesTheParticipant(): void
    {
        $thread = $this->createGuestThread();
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];
        $raw = $this->plugin()->participants->mintToken($participant);

        $found = $this->plugin()->participants->findByToken($raw);

        self::assertNotNull($found);
        self::assertSame($participant->id, $found->id);
    }

    public function testFindByTokenRejectsEmptyUnknownAndExpiredTokens(): void
    {
        $thread = $this->createGuestThread();
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];
        $raw = $this->plugin()->participants->mintToken($participant);

        self::assertNull($this->plugin()->participants->findByToken(''));
        self::assertNull($this->plugin()->participants->findByToken('   '));
        self::assertNull($this->plugin()->participants->findByToken('not-a-real-token'));

        $participant->tokenExpiresAt = Db::prepareDateForDb((new DateTime())->sub(new DateInterval('PT1M')));
        $participant->save(false);

        self::assertNull($this->plugin()->participants->findByToken($raw), 'Expired tokens must be rejected');
    }

    /**
     * Expiries are stored in UTC. Parsing them in the system time zone would let
     * a token stay usable for the length of the UTC offset past its expiry.
     */
    public function testTokenExpiryIsEvaluatedInUtc(): void
    {
        $thread = $this->createGuestThread();
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];
        $raw = $this->plugin()->participants->mintToken($participant);

        $utc = new DateTimeZone('UTC');

        $participant->tokenExpiresAt = (new DateTime('now', $utc))->sub(new DateInterval('PT1M'))->format('Y-m-d H:i:s');
        $participant->save(false);
        self::assertNull(
            $this->plugin()->participants->findByToken($raw),
            'A token that expired a minute ago (UTC) must be rejected in every time zone',
        );

        $participant->tokenExpiresAt = (new DateTime('now', $utc))->add(new DateInterval('PT1M'))->format('Y-m-d H:i:s');
        $participant->save(false);
        self::assertNotNull(
            $this->plugin()->participants->findByToken($raw),
            'A token with a minute left (UTC) must still work in every time zone',
        );
    }

    public function testFindByTokenRejectsParticipantsWhoLeft(): void
    {
        $thread = $this->createGuestThread();
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];
        $raw = $this->plugin()->participants->mintToken($participant);

        $participant->leftAt = Db::prepareDateForDb(new DateTime());
        $participant->save(false);

        self::assertNull($this->plugin()->participants->findByToken($raw));
    }

    public function testTokenLifetimeFollowsTheSetting(): void
    {
        $thread = $this->createGuestThread();
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];

        $original = $this->plugin()->getSettings()->guestTokenLifetimeDays;
        $this->plugin()->getSettings()->guestTokenLifetimeDays = 2;

        try {
            $this->plugin()->participants->mintToken($participant);
            $utc = new DateTimeZone('UTC');
            $expires = new DateTime($participant->tokenExpiresAt, $utc);
            $expected = (new DateTime('now', $utc))->add(new DateInterval('P2D'));

            self::assertLessThan(120, abs($expires->getTimestamp() - $expected->getTimestamp()));
        } finally {
            $this->plugin()->getSettings()->guestTokenLifetimeDays = $original;
        }
    }

    public function testGetActiveGuestsByEmail(): void
    {
        $threadA = $this->createGuestThread('shared@example.test', 'A');
        $threadB = $this->createGuestThread('shared@example.test', 'B');
        $this->createGuestThread('someone-else@example.test', 'C');

        $guests = $this->plugin()->participants->getActiveGuestsByEmail(' Shared@Example.test ');

        self::assertCount(2, $guests);
        $threadIds = array_map(static fn($p) => $p->threadId, $guests);
        self::assertContains($threadA->id, $threadIds);
        self::assertContains($threadB->id, $threadIds);
    }

    public function testGetActiveGuestsByEmailIgnoresDepartedGuestsAndBlankEmails(): void
    {
        $thread = $this->createGuestThread('departed@example.test');
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];
        $participant->leftAt = Db::prepareDateForDb(new DateTime());
        $participant->save(false);

        self::assertSame([], $this->plugin()->participants->getActiveGuestsByEmail('departed@example.test'));
        self::assertSame([], $this->plugin()->participants->getActiveGuestsByEmail('  '));
    }

    public function testMarkReadAdvancesHighWaterMarkAndWritesReceipts(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();
        $this->plugin()->messages->post($thread, ['body' => 'hello', 'authorUserId' => $staff->id]);

        $guest = $this->plugin()->participants->getForThread($thread->id)[0];
        $messages = $this->messagesFor($thread->id);
        $latestId = end($messages)->id;

        $this->plugin()->participants->markRead($guest);

        self::assertSame($latestId, $guest->lastReadMessageId);
        self::assertNotNull($guest->lastReadAt);
        self::assertCount(
            count($messages),
            MessageReadRecord::findAll(['participantId' => $guest->id]),
            'One receipt per message read',
        );
    }

    public function testMarkReadNeverMovesBackwardsAndDoesNotDuplicateReceipts(): void
    {
        $thread = $this->createGuestThread();
        $staff = $this->createStaffUser();
        $this->plugin()->messages->post($thread, ['body' => 'hello', 'authorUserId' => $staff->id]);

        $guest = $this->plugin()->participants->getForThread($thread->id)[0];
        $messages = $this->messagesFor($thread->id);
        $latestId = end($messages)->id;

        $this->plugin()->participants->markRead($guest);
        $receipts = count(MessageReadRecord::findAll(['participantId' => $guest->id]));

        // Re-marking an older message must not rewind the high-water mark.
        $this->plugin()->participants->markRead($guest, $messages[0]->id);

        self::assertSame($latestId, $guest->lastReadMessageId);
        self::assertCount($receipts, MessageReadRecord::findAll(['participantId' => $guest->id]));
    }

    public function testMarkReadIsANoOpOnAnEmptyThread(): void
    {
        $thread = $this->plugin()->threads->createSupportThread('Empty', 'empty@example.test');
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];

        $this->plugin()->participants->markRead($participant);

        self::assertNull($participant->lastReadMessageId);
        self::assertSame([], MessageReadRecord::findAll(['participantId' => $participant->id]));
    }

    public function testUnreadThreadCountForUser(): void
    {
        $reader = $this->createUser('unread-reader@example.test');
        $writer = $this->createUser('unread-writer@example.test');

        $thread = $this->plugin()->threads->createDirectThread('Unread', $writer->id, [$reader->id]);
        self::assertSame(0, $this->plugin()->participants->unreadThreadCountForUser($reader->id));

        $this->plugin()->messages->post($thread, ['body' => 'you there?', 'authorUserId' => $writer->id]);
        self::assertSame(1, $this->plugin()->participants->unreadThreadCountForUser($reader->id));
        self::assertSame(0, $this->plugin()->participants->unreadThreadCountForUser($writer->id), 'Your own message is not unread');

        $participant = $this->plugin()->participants->getForUser($thread->id, $reader->id);
        $this->plugin()->participants->markRead($participant);
        self::assertSame(0, $this->plugin()->participants->unreadThreadCountForUser($reader->id));
    }

    public function testClosedThreadsAreExcludedFromTheUnreadCount(): void
    {
        $reader = $this->createUser('closed-reader@example.test');
        $writer = $this->createUser('closed-writer@example.test');

        $thread = $this->plugin()->threads->createDirectThread('Closing', $writer->id, [$reader->id]);
        $this->plugin()->messages->post($thread, ['body' => 'bye', 'authorUserId' => $writer->id]);
        self::assertSame(1, $this->plugin()->participants->unreadThreadCountForUser($reader->id));

        $this->plugin()->threads->setStatus($this->reloadThread($thread->id), \justinholtweb\pigeon\enums\ThreadStatus::Closed);

        self::assertSame(0, $this->plugin()->participants->unreadThreadCountForUser($reader->id));
    }
}
