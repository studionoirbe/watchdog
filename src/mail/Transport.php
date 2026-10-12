<?php

namespace studionoir\watchdog\mail;

use studionoir\watchdog\services\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps the site's real mail transport. It is the last stop before a mail leaves Craft, so it sees
 * every send whatever code started it, including the exception Craft swallows.
 */
class Transport implements TransportInterface
{
    public function __construct(private TransportInterface $inner, private Mail $mail)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $forced = $this->mail->forcedRecipient;
        if ($forced !== null) {
            $envelope = $this->enforce($message, $envelope, $forced);
        }

        try {
            $sent = $this->inner->send($message, $envelope);
        } catch (\Throwable $e) {
            $this->mail->report($message, $envelope, $e->getMessage());
            throw $e;
        }

        $this->mail->report($message, $envelope, null);

        return $sent;
    }

    public function __toString(): string
    {
        return (string)$this->inner;
    }

    /** Code that calls transport-specific methods (SMTP stream options and the like) keeps working. */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->$name(...$arguments);
    }

    private function enforce(RawMessage $message, ?Envelope $envelope, string $forced): ?Envelope
    {
        if ($message instanceof Email) {
            $message->to($forced);
            $message->getHeaders()->remove('Cc');
            $message->getHeaders()->remove('Bcc');

            // No explicit envelope: Symfony derives the recipients from the rewritten headers.
            return null;
        }

        // A signed or encrypted message cannot be rewritten. Let it through only when it is already
        // addressed to the forced recipient and nobody else.
        try {
            $recipients = array_map(
                static fn(Address $address) => strtolower($address->getAddress()),
                ($envelope ?? Envelope::create($message))->getRecipients(),
            );
        } catch (\Throwable) {
            $recipients = null;
        }

        if ($recipients === null || array_values(array_unique($recipients)) !== [strtolower($forced)]) {
            throw new TransportException('Watchdog: testinzending met vaste ontvanger actief; mail naar andere ontvangers is tegengehouden.');
        }

        return $envelope;
    }
}
