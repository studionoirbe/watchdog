<?php

namespace studionoir\watchdog\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use studionoir\watchdog\mail\Transport;
use studionoir\watchdog\Plugin;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message as MimeMessage;
use Symfony\Component\Mime\RawMessage;
use yii\base\Component;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;
use yii\symfonymailer\Mailer as SymfonyMailer;

/**
 * Hooks Craft's mailer: records every failed send in the plugin's own table and, during a test
 * submission, forces every mail to one recipient.
 *
 * Why not just listen to the mailer's afterSend event: on Craft 5 a transport error is thrown past
 * that event and Craft's Mailer::send() only returns false, and on Craft 4 the event does fire but
 * the error text is lost. Wrapping the transport gives the same result, with the error, on both.
 */
class Mail extends Component
{
    public const TABLE = '{{%watchdog_failures}}';
    public const KEEP_DAYS = 90;

    /** While set, no mail leaves the transport for any other address. */
    public ?string $forcedRecipient = null;

    /** @var \WeakMap<object,array> Which form plugin is sending a given message. */
    private \WeakMap $contexts;

    private ?array $context = null;
    private bool $reported = false;
    private ?array $lastFailure = null;
    private ?array $attempts = null;

    public function init(): void
    {
        parent::init();
        $this->contexts = new \WeakMap();
    }

    public function listen(): void
    {
        Event::on(BaseMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) {
            $attached = $this->attach($event->sender);
            if ($this->forcedRecipient !== null && !$attached) {
                // Without the transport wrapper nothing guarantees where this mail ends up.
                $event->isValid = false;

                return;
            }
            $this->guarded(fn() => $this->beforeSend($event));
        });
        Event::on(BaseMailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $event) {
            $this->guarded(fn() => $this->afterSend($event));
        });

        // Class names as strings: these plugins are optional and must not be autoloaded here.
        Event::on('verbb\formie\services\Emails', 'beforeSendEmail', function($event) {
            $this->guarded(fn() => $this->contexts[$event->email] = [
                'origin' => 'formie',
                'name' => $event->notification->name ?? null,
                'form' => $event->submission->getForm()?->title,
                'submissionId' => $event->submission->id ?: null,
                'notificationId' => $event->notification->id ?? null,
            ]);
        });
        Event::on('verbb\formie\elements\SentNotification', 'afterSave', function($event) {
            $this->guarded(fn() => $this->linkFormieLog($event->sender));
        });
        Event::on('Solspace\Freeform\Services\MailerService', 'beforeSend', function($event) {
            $this->guarded(function() use ($event) {
                $record = method_exists($event, 'getNotificationRecord') ? $event->getNotificationRecord() : null;
                $this->contexts[$event->getMessage()] = [
                    'origin' => 'freeform',
                    'name' => $record?->getName() ?? $event->getNotification()->getName(),
                    'form' => $event->getForm()->getName(),
                    'submissionId' => $event->getSubmission()?->id ?: null,
                    'notificationId' => null,
                ];
            });
        });
    }

    /**
     * Puts our wrapper around the mailer's transport. Returns false when that is not possible, in
     * which case failures are only seen through afterSend and a forced recipient cannot be guaranteed.
     */
    public function attach(mixed $mailer): bool
    {
        if (!$mailer instanceof SymfonyMailer) {
            return false;
        }

        try {
            // getTransport() is public in yii2-symfonymailer 2.x (Craft 4) and private in 4.x (Craft 5).
            $transport = \Closure::bind(fn() => $this->getTransport(), $mailer, SymfonyMailer::class);
            if (!$transport() instanceof Transport) {
                $mailer->setTransport(new Transport($transport(), $this));
            }

            return $transport() instanceof Transport;
        } catch (\Throwable $e) {
            Craft::warning('Watchdog kon het mailtransport niet inpakken: ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }

    /** Called by the transport wrapper after every send, successful or not. */
    public function report(RawMessage $message, ?Envelope $envelope, ?string $error): void
    {
        $this->guarded(function() use ($message, $envelope, $error) {
            $this->reported = true;
            $to = [];
            $subject = null;
            if ($message instanceof MimeMessage) {
                $subject = $message->getHeaders()->getHeaderBody('Subject');
                try {
                    $to = array_map(
                        static fn(Address $address) => $address->getAddress(),
                        ($envelope ?? Envelope::create($message))->getRecipients(),
                    );
                } catch (\Throwable) {
                }
            }
            $this->conclude(array_values(array_unique($to)), is_string($subject) ? $subject : null, $error);
        });
    }

    public function startCapture(): void
    {
        $this->attempts = [];
    }

    /** @return array<int,array{name:?string,to:string[],subject:?string,error:?string}> */
    public function stopCapture(): array
    {
        $attempts = $this->attempts ?? [];
        $this->attempts = null;

        return $attempts;
    }

    /**
     * Rows from our own table, newest first. Rows that a form plugin's own log also reports are left
     * out unless that log cannot be read (any more).
     */
    public function failures(int $since, int $limit, bool $includeReported): array
    {
        $query = (new Query())
            ->from(self::TABLE)
            ->where(['>=', 'occurredAt', $since])
            ->orderBy(['occurredAt' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if (!$includeReported) {
            $query->andWhere(['reportedAs' => null]);
        }

        return $query->all();
    }

    /** Transport errors for failures that a form plugin's log reports, keyed by that log's id. */
    public function errorsFor(array $reportedAs): array
    {
        if (!$reportedAs) {
            return [];
        }

        return (new Query())
            ->select(['reportedAs', 'error'])
            ->from(self::TABLE)
            ->where(['reportedAs' => $reportedAs])
            ->pairs();
    }

    public function prune(): void
    {
        $this->guarded(fn() => Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['<', 'occurredAt', time() - self::KEEP_DAYS * 86400])
            ->execute());
    }

    public function tableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }

    /** Keeps the SMTP password and our own key out of anything we store or return. */
    public function scrub(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $secrets = [];
        try {
            $settings = App::mailSettings();
            $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);
            $secrets[] = App::parseEnv($adapter->password ?? null);
        } catch (\Throwable) {
        }
        $secrets[] = Plugin::getInstance()?->getApiKey();

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }

    private function beforeSend(MailEvent $event): void
    {
        $this->context = $this->contexts[$event->message] ?? null;
        $this->reported = false;

        if ($this->forcedRecipient !== null) {
            // The transport wrapper enforces this; doing it here as well makes the form plugin's
            // own log show the address that was really used.
            $event->message->setTo($this->forcedRecipient);
            $event->message->setCc([]);
            $event->message->setBcc([]);
        }
    }

    private function afterSend(MailEvent $event): void
    {
        // Only reached with a failure when the transport wrapper is missing (attach() failed).
        if (!$event->isSuccessful && !$this->reported) {
            $to = $event->message->getTo();
            $this->conclude(
                is_array($to) ? array_map('strval', array_keys($to)) : array_filter([(string)$to]),
                $event->message->getSubject(),
                'Verzenden mislukt; het transport gaf geen foutmelding door.',
            );
        }
        $this->context = null;
    }

    private function conclude(array $to, ?string $subject, ?string $error): void
    {
        $context = $this->context;
        $this->context = null;
        $error = $this->scrub($error);

        if ($this->attempts !== null) {
            $this->attempts[] = ['name' => $context['name'] ?? null, 'to' => $to, 'subject' => $subject, 'error' => $error];
        }

        if ($error === null) {
            return;
        }

        $db = Craft::$app->getDb();
        $db->createCommand()->insert(self::TABLE, [
            'occurredAt' => time(),
            'origin' => $context['origin'] ?? null,
            'form' => isset($context['form']) ? mb_substr((string)$context['form'], 0, 255) : null,
            'submissionId' => $context['submissionId'] ?? null,
            'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'recipients' => implode(', ', $to),
            'error' => mb_substr($error, 0, 2000),
        ])->execute();

        $this->lastFailure = ['id' => (int)$db->getLastInsertID(self::TABLE), 'context' => $context];
    }

    /**
     * Formie writes its own failed "sent notification" right after the send fails. Mark our row as
     * covered by it, so the same failure is not listed twice.
     */
    private function linkFormieLog(object $sentNotification): void
    {
        $context = $this->lastFailure['context'] ?? null;
        if (
            $context === null
            || $context['origin'] !== 'formie'
            || ($sentNotification->success ?? true)
            || (int)$sentNotification->notificationId !== (int)$context['notificationId']
            || (int)$sentNotification->submissionId !== (int)$context['submissionId']
        ) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, ['reportedAs' => 'formie:' . $sentNotification->id], ['id' => $this->lastFailure['id']])
            ->execute();
        $this->lastFailure = null;
    }

    /** Our bookkeeping must never be the reason a client's mail fails. */
    private function guarded(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Craft::warning('Watchdog: ' . $e->getMessage(), __METHOD__);
        }
    }
}
