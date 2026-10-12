<?php

namespace studionoir\watchdog\services;

use Craft;
use studionoir\watchdog\ApiError;
use studionoir\watchdog\Plugin;
use yii\base\Component;
use yii\base\Event;

/**
 * Freeform 5 (Craft 4 and 5). Freeform is optional, so its classes are only ever named as strings.
 * Older Freeform majors have a different API and are reported as unsupported.
 */
class Freeform extends Component
{
    public const NOTIFICATION_JOB_PREFIX = 'Solspace\Freeform\Jobs\SendNotification';

    private const SUBMISSION = 'Solspace\Freeform\Elements\Submission';
    private const FORM = 'Solspace\Freeform\Form\Form';
    private const SEND_EVENT = 'Solspace\Freeform\Events\Forms\SendNotificationsEvent';
    private const NOTIFICATIONS = 'Solspace\Freeform\Bundles\Notifications\Providers\NotificationsProvider';

    public function plugin(): ?object
    {
        return Craft::$app->getPlugins()->getPlugin('freeform');
    }

    public function supported(): bool
    {
        $plugin = $this->plugin();

        return $plugin
            && version_compare($plugin->getVersion(), '5.0.0', '>=')
            && class_exists(self::SEND_EVENT)
            && class_exists(self::NOTIFICATIONS);
    }

    public function status(): array
    {
        $plugin = $this->plugin();
        if (!$plugin) {
            $disabled = Craft::$app->getPlugins()->isPluginInstalled('freeform');

            return ['installed' => false, 'version' => null, 'failureLog' => false, 'note' => $disabled ? 'Geïnstalleerd maar uitgeschakeld' : null];
        }

        return ['installed' => true, 'version' => $plugin->getVersion(), 'failureLog' => false, 'note' => $this->logNote()];
    }

    /** Freeform has no log of failed notifications that can be read back, in any version. */
    public function logNote(): string
    {
        return $this->supported()
            ? 'Freeform houdt zelf geen log bij van mislukte meldingen. Mislukte Freeform-mails staan onder de bron “mailer”.'
            : 'Deze Freeform-versie wordt niet ondersteund (vanaf 5.0). Mislukte mails staan onder de bron “mailer”.';
    }

    public function forms(): array
    {
        if (!$this->supported()) {
            return [];
        }

        $provider = Craft::$container->get(self::NOTIFICATIONS);
        $forms = [];
        foreach ($this->plugin()->forms->getAllForms() as $form) {
            $fields = [];
            foreach ($form->getLayout()->getFields() as $field) {
                if (!$field->getHandle() || !$field->canStoreValues()) {
                    continue;
                }
                $fields[] = [
                    'handle' => (string)$field->getHandle(),
                    'label' => (string)$field->getLabel(),
                    'type' => (string)$field->getType(),
                    'required' => (bool)$field->isRequired(),
                ];
            }

            $notifications = [];
            foreach ($provider->getByForm($form) as $notification) {
                $notifications[] = [
                    'name' => (string)$notification->getName(),
                    'enabled' => (bool)$notification->isEnabled(),
                    'to' => $this->configuredTo($notification),
                ];
            }

            $forms[] = [
                'plugin' => 'freeform',
                'id' => (int)$form->getId(),
                'handle' => (string)$form->getHandle(),
                'name' => (string)$form->getName(),
                'fields' => $fields,
                'notifications' => $notifications,
            ];
        }

        return $forms;
    }

    /**
     * @param \ArrayObject $jobs Freeform notification jobs the controller keeps out of the queue
     */
    public function submit(string $ref, array $values, string $testEmail, bool $delete, \ArrayObject $jobs): array
    {
        $plugin = $this->plugin();
        if (!$plugin) {
            throw new ApiError(422, 'Freeform is niet geïnstalleerd of staat uit.');
        }
        if (!$this->supported()) {
            throw new ApiError(422, 'Testinzendingen worden voor deze Freeform-versie niet ondersteund (vanaf 5.0).');
        }

        $form = $plugin->forms->getFormByHandle($ref);
        if (!$form && ctype_digit($ref)) {
            $form = $plugin->forms->getFormById((int)$ref);
        }
        if (!$form) {
            throw new ApiError(404, "Freeform-formulier “{$ref}” bestaat niet.");
        }

        // Same steps as Freeform's own "submissions/generate" command.
        $class = self::SUBMISSION;
        $submission = $class::create($form);
        $submission->statusId = $form->getSettings()->getGeneral()->defaultStatus;
        $submission->setFormFieldValues($this->values($form, $values, $testEmail));
        $submission->title = Plugin::TEST_VALUE;

        $elements = Craft::$app->getElements();
        if (!$elements->saveElement($submission, false)) {
            throw new ApiError(422, 'De testinzending kon niet bewaard worden: ' . implode(' ', $submission->getErrorSummary(true)));
        }

        $mail = Plugin::getInstance()->mail;
        $settings = $plugin->settings->getSettingsModel();
        $useQueue = $settings->useQueueForEmailNotifications ?? null;
        $notifications = [];

        try {
            // Same steps as Freeform's "Resend notifications" action. Deliberately not the
            // "process submission" event: that one also feeds CRM, mailing-list and webhook integrations.
            $form->valuesFromSubmission($submission);
            $siteId = $submission->siteId ?: Craft::$app->getSites()->getCurrentSite()->id;
            $eventClass = self::SEND_EVENT;

            $mail->startCapture();
            try {
                // Make Freeform queue its notification jobs. The controller intercepts them, so each
                // can be run here on its own and its result reported.
                if ($useQueue !== null) {
                    $settings->useQueueForEmailNotifications = is_bool($useQueue) ? true : 'true';
                }
                Event::trigger(self::FORM, 'send-notifications', new $eventClass($form, $submission, $plugin->mailer, $siteId));
            } finally {
                if ($useQueue !== null) {
                    $settings->useQueueForEmailNotifications = $useQueue;
                }
                // Anything Freeform sent directly anyway.
                foreach ($mail->stopCapture() as $sent) {
                    $notifications[] = $this->result($sent['name'], $sent);
                }
            }

            $provider = Craft::$container->get(self::NOTIFICATIONS);
            foreach ($jobs->exchangeArray([]) as $job) {
                $record = !empty($job->notificationId) ? $provider->getByFormAndId($form, (int)$job->notificationId) : null;
                $name = $record?->getName() ?? $job->template?->getName();

                $error = null;
                $mail->startCapture();
                try {
                    $job->execute(Craft::$app->getQueue());
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                } finally {
                    $sentMails = $mail->stopCapture();
                }

                foreach ($sentMails as $sent) {
                    $notifications[] = $this->result($name, $sent);
                }
                if (!$sentMails) {
                    // Freeform catches its own errors (template mistakes, no recipient) and only logs them.
                    $notifications[] = [
                        'name' => (string)($name ?? 'Melding'),
                        'success' => false,
                        'error' => $error ?? 'Freeform heeft deze melding niet verstuurd; zie storage/logs/freeform-email.log.',
                        'to' => $mail->forcedRecipient ?? implode(', ', $job->recipients?->emailsToArray() ?? []),
                    ];
                }
            }
        } finally {
            // Through Freeform's own service: deleting the element directly is refused without a
            // logged-in user with permission.
            $deleted = $delete && $plugin->submissions->delete($class::find()->id($submission->id), true, true);
        }

        return ['submissionId' => (int)$submission->id, 'deleted' => $deleted, 'notifications' => $notifications];
    }

    private function result(?string $name, array $sent): array
    {
        return [
            'name' => (string)($name ?? $sent['name'] ?? 'Melding'),
            'success' => $sent['error'] === null,
            'error' => $sent['error'],
            'to' => implode(', ', $sent['to']),
        ];
    }

    private function configuredTo(object $notification): string
    {
        $emails = method_exists($notification, 'getRecipients') ? $notification->getRecipients()->emailsToArray() : [];
        if (!$emails && method_exists($notification, 'getField') && $notification->getField()) {
            // Sent to whatever address is entered in this form field.
            return '{' . $notification->getField()->getHandle() . '}';
        }

        return implode(', ', $emails);
    }

    /** The caller's values, plus a recognisable test value for every required field left empty. */
    private function values(object $form, array $given, string $testEmail): array
    {
        $values = [];
        foreach ($form->getLayout()->getFields() as $field) {
            $handle = $field->getHandle();
            if (!$handle) {
                continue;
            }
            if (array_key_exists($handle, $given)) {
                $values[$handle] = $given[$handle];
            } elseif ($field->isRequired()) {
                $value = $this->testValue($field, $testEmail);
                if ($value !== null) {
                    $values[$handle] = $value;
                }
            }
        }

        return $values;
    }

    private function testValue(object $field, string $testEmail): mixed
    {
        $firstOption = static function() use ($field) {
            foreach (method_exists($field, 'getOptions') ? $field->getOptions() : [] as $option) {
                if (method_exists($option, 'getValue') && (string)$option->getValue() !== '') {
                    return $option->getValue();
                }
            }

            return null;
        };

        switch ($field->getType()) {
            case 'email':
                return $testEmail;
            case 'text':
            case 'textarea':
            case 'hidden':
                return Plugin::TEST_VALUE;
            case 'number':
                return 1;
            case 'checkbox':
                return true;
            case 'phone':
                return '+32470000000';
            case 'website':
                return 'https://example.com';
            case 'dropdown':
            case 'radios':
                return $firstOption();
            case 'checkboxes':
            case 'multiple-select':
                $option = $firstOption();

                return $option !== null ? [$option] : null;
            default:
                // Files, dates, signatures, tables, payments: left empty.
                return null;
        }
    }
}
