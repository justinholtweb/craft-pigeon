<?php

namespace justinholtweb\pigeontests\unit\jobs;

use Craft;
use craft\mail\Message;
use craft\test\TestMailer;
use justinholtweb\pigeon\jobs\SendMessageNotification;
use justinholtweb\pigeontests\unit\PigeonTestCase;

class SendMessageNotificationTest extends PigeonTestCase
{
    /** @var Message[] */
    private array $sent = [];

    protected function _before(): void
    {
        parent::_before();

        $this->sent = [];
        Craft::$app->set('mailer', new TestMailer([
            'messageClass' => Message::class,
            'callback' => function(Message $message): void {
                $this->sent[] = $message;
            },
        ]));
    }

    public function testSendsToAGuestParticipantWithATokenLink(): void
    {
        $thread = $this->createGuestThread('job-guest@example.test');
        $participant = $this->plugin()->participants->getForThread($thread->id)[0];
        $message = $this->messagesFor($thread->id)[0];

        (new SendMessageNotification([
            'messageId' => $message->id,
            'participantId' => $participant->id,
        ]))->execute(Craft::$app->getQueue());

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame(['job-guest@example.test'], $this->recipientsOf($email));
        self::assertStringContainsString($thread->title, $email->getSubject());

        // The guest link is a freshly minted token, not the participant ID.
        $participant->refresh();
        self::assertNotNull($participant->tokenHash);
        self::assertStringContainsString('pigeon/t/', $this->bodyOf($email));
    }

    public function testSendsToAStaffParticipantWithAControlPanelLink(): void
    {
        $thread = $this->createGuestThread('job-staff@example.test');
        $staff = $this->createStaffUser('job-staff-user@example.test');
        $participant = $this->plugin()->participants->ensureUser(
            $thread->id,
            $staff->id,
            \justinholtweb\pigeon\enums\ParticipantRole::Admin->value,
        );
        $message = $this->messagesFor($thread->id)[0];

        (new SendMessageNotification([
            'messageId' => $message->id,
            'participantId' => $participant->id,
        ]))->execute(Craft::$app->getQueue());

        self::assertCount(1, $this->sent);
        self::assertStringContainsString("pigeon/threads/{$thread->id}", $this->bodyOf($this->sent[0]));
    }

    public function testAdhocStaffAlert(): void
    {
        $thread = $this->createGuestThread('job-adhoc@example.test');
        $message = $this->messagesFor($thread->id)[0];

        (new SendMessageNotification([
            'messageId' => $message->id,
            'adhocEmail' => 'support@example.test',
            'staffAlert' => true,
        ]))->execute(Craft::$app->getQueue());

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame(['support@example.test'], $this->recipientsOf($email));
        self::assertStringContainsString('New support message', $email->getSubject());
    }

    public function testMissingMessageOrParticipantSendsNothing(): void
    {
        (new SendMessageNotification(['messageId' => 999999]))->execute(Craft::$app->getQueue());

        $thread = $this->createGuestThread('job-missing@example.test');
        $message = $this->messagesFor($thread->id)[0];
        (new SendMessageNotification([
            'messageId' => $message->id,
            'participantId' => 999999,
        ]))->execute(Craft::$app->getQueue());

        self::assertCount(0, $this->sent);
    }

    public function testFromAddressFollowsTheSettings(): void
    {
        $settings = $this->plugin()->getSettings();
        $originalEmail = $settings->fromEmail;
        $originalName = $settings->fromName;
        $settings->fromEmail = 'pigeon@example.test';
        $settings->fromName = 'Pigeon Support';

        try {
            $thread = $this->createGuestThread('job-from@example.test');
            $message = $this->messagesFor($thread->id)[0];

            (new SendMessageNotification([
                'messageId' => $message->id,
                'adhocEmail' => 'support@example.test',
                'staffAlert' => true,
            ]))->execute(Craft::$app->getQueue());
        } finally {
            $settings->fromEmail = $originalEmail;
            $settings->fromName = $originalName;
        }

        self::assertSame(['pigeon@example.test' => 'Pigeon Support'], $this->sent[0]->getFrom());
    }

    private function bodyOf(Message $message): string
    {
        $email = $message->getSymfonyEmail();

        return ($email->getHtmlBody() ?? '') . "\n" . ($email->getTextBody() ?? '');
    }

    /**
     * @return string[]
     */
    private function recipientsOf(Message $message): array
    {
        return array_map(
            static fn(\Symfony\Component\Mime\Address $address) => $address->getAddress(),
            $message->getSymfonyEmail()->getTo(),
        );
    }
}
