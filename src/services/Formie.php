<?php

namespace studionoir\watchdog\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use studionoir\watchdog\ApiError;
use studionoir\watchdog\Plugin;
use yii\base\Component;

/**
 * Formie 2 (Craft 4) and Formie 3 (Craft 5). Formie is optional, so its classes are only ever named
 * as strings and nothing here runs unless the plugin is installed and enabled.
 */
class Formie extends Component
{
    private const SUBMISSION = 'verbb\formie\elements\Submission';
    private const LOG_TABLE = '{{%formie_sentnotifications}}';

    public function plugin(): ?object
    {
        return Craft::$app->getPlugins()->getPlugin('formie');
    }

    public function status(): array
    {
        $plugin = $this->plugin();
        if (!$plugin) {
            $disabled = Craft::$app->getPlugins()->isPluginInstalled('formie');

            return ['installed' => false, 'version' => null, 'failureLog' => false, 'note' => $disabled ? 'Geïnstalleerd maar uitgeschakeld' : null];
        }

        $note = $this->logNote($plugin);

        return ['installed' => true, 'version' => $plugin->getVersion(), 'failureLog' => $note === null, 'note' => $note];
    }

    /** Null when Formie keeps a usable log of failed notifications, otherwise why not. */
    public function logNote(object $plugin): ?string
    {
        if (!($plugin->getSettings()->sentNotifications ?? false)) {
            return 'Zet in Formie → Instellingen → Sent Notifications het bijhouden van verzonden meldingen aan.';
        }
        if (Craft::$app->getDb()->getTableSchema(self::LOG_TABLE)?->getColumn('success') === null) {
            return 'Deze Formie-versie bewaart niet of een melding gelukt is; werk Formie bij.';
        }

        return null;
    }

    public function forms(): array
    {
        $plugin = $this->plugin();
        if (!$plugin) {
            return [];
        }

        $forms = [];
        foreach ($plugin->getForms()->getAllForms() as $form) {
            $fields = [];
            foreach ($form->getCustomFields() as $field) {
                if ($field->getIsCosmetic()) {
                    continue;
                }
                $fields[] = [
                    'handle' => (string)$field->handle,
                    // Formie 3 calls it "label", Formie 2 uses Craft's field "name".
                    'label' => (string)($field->label ?? $field->name ?? $field->handle),
                    'type' => lcfirst((new \ReflectionClass($field))->getShortName()),
                    'required' => (bool)$field->required,
                ];
            }

            $notifications = [];
            foreach ($form->getNotifications() ?? [] as $notification) {
                $notifications[] = [
                    'name' => (string)$notification->name,
                    'enabled' => (bool)$notification->enabled,
                    'to' => $this->configuredTo($notification),
                ];
            }

            $forms[] = [
                'plugin' => 'formie',
                'id' => (int)$form->id,
                'handle' => (string)$form->handle,
                'name' => (string)$form->title,
                'fields' => $fields,
                'notifications' => $notifications,
            ];
        }

        return $forms;
    }

    public function submit(string $ref, array $values, string $testEmail, bool $delete): array
    {
        $plugin = $this->plugin();
        if (!$plugin) {
            throw new ApiError(422, 'Formie is niet geïnstalleerd of staat uit.');
        }

        $form = $plugin->getForms()->getFormByHandle($ref);
        if (!$form && ctype_digit($ref)) {
            $form = $plugin->getForms()->getFormById((int)$ref);
        }
        if (!$form) {
            throw new ApiError(404, "Formie-formulier “{$ref}” bestaat niet.");
        }

        $class = self::SUBMISSION;
        $submission = new $class();
        $submission->setForm($form);
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->title = Plugin::TEST_VALUE;
        if (property_exists($submission, 'isNewSubmission')) {
            // Formie 3: stops afterSave() from treating this as a status change and queueing notifications.
            $submission->isNewSubmission = true;
        }
        $submission->setFieldValues($this->values($form, $values, $testEmail));

        // Saved without validation on purpose: that is where captchas and required-field rules run.
        $elements = Craft::$app->getElements();
        if (!$elements->saveElement($submission, false)) {
            throw new ApiError(422, 'De testinzending kon niet bewaard worden: ' . implode(' ', $submission->getErrorSummary(true)));
        }

        $mail = Plugin::getInstance()->mail;
        $notifications = [];
        try {
            foreach ($form->getEnabledNotifications() as $notification) {
                if (!$plugin->getNotifications()->evaluateConditions($notification, $submission)) {
                    continue;
                }

                // Straight to the mailer, never through Formie's queue job, so the outcome is known now.
                $mail->startCapture();
                try {
                    $result = $plugin->getSubmissions()->sendNotificationEmail($notification, $submission);
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                } finally {
                    $mails = $mail->stopCapture();
                }

                $transportError = null;
                $to = [];
                foreach ($mails as $sent) {
                    $transportError ??= $sent['error'];
                    $to = array_merge($to, $sent['to']);
                }

                $success = is_array($result) && !empty($result['success']) && $transportError === null;
                $notifications[] = [
                    'name' => (string)$notification->name,
                    'success' => $success,
                    // Formie's own message has no transport detail ("…failed to send the notification: “”").
                    'error' => $success ? null : ($transportError
                        ?? (is_array($result) ? ($result['error'] ?? null) : null)
                        ?? 'De melding is niet verstuurd (geannuleerd door Formie of een andere plugin).'),
                    'to' => $to ? implode(', ', array_unique($to)) : ($mail->forcedRecipient ?? $this->configuredTo($notification)),
                ];
            }
        } finally {
            $deleted = $delete && $elements->deleteElement($submission, true);
        }

        return ['submissionId' => (int)$submission->id, 'deleted' => $deleted, 'notifications' => $notifications];
    }

    public function failures(int $since, int $limit): array
    {
        $plugin = $this->plugin();
        if (!$plugin || $this->logNote($plugin) !== null) {
            return [];
        }

        $rows = (new Query())
            ->select(['sn.id', 'sn.formId', 'sn.submissionId', 'sn.subject', 'sn.to', 'sn.message', 'sn.dateCreated'])
            ->from(['sn' => self::LOG_TABLE])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[sn.id]]')
            ->where(['sn.success' => false, 'e.dateDeleted' => null])
            ->andWhere(['>=', 'sn.dateCreated', Db::prepareDateForDb(new \DateTime('@' . $since))])
            ->orderBy(['sn.dateCreated' => SORT_DESC, 'sn.id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $ids = array_map(static fn(array $row) => 'formie:' . $row['id'], $rows);
        $transportErrors = Plugin::getInstance()->mail->errorsFor($ids);
        $formNames = [];

        $failures = [];
        foreach ($rows as $row) {
            $id = 'formie:' . $row['id'];
            $formId = $row['formId'] !== null ? (int)$row['formId'] : null;
            if ($formId !== null && !array_key_exists($formId, $formNames)) {
                $formNames[$formId] = $plugin->getForms()->getFormById($formId)?->title;
            }

            $failures[] = [
                'id' => $id,
                'source' => 'formie',
                'occurredAt' => (new \DateTime($row['dateCreated'], new \DateTimeZone('UTC')))->getTimestamp(),
                'form' => $formId !== null ? $formNames[$formId] : null,
                'subject' => $row['subject'],
                'to' => $row['to'],
                'error' => $transportErrors[$id] ?? $row['message'],
                'submissionId' => $row['submissionId'] !== null ? (int)$row['submissionId'] : null,
                'cpUrl' => UrlHelper::cpUrl('formie/sent-notifications/edit/' . $row['id']),
            ];
        }

        return $failures;
    }

    private function configuredTo(object $notification): string
    {
        if (($notification->recipients ?? 'email') !== 'conditions') {
            return (string)$notification->to;
        }

        $emails = array_column($notification->toConditions['toRecipients'] ?? [], 'email');

        return implode(', ', array_unique(array_filter($emails)));
    }

    /** The caller's values, plus a recognisable test value for every required field left empty. */
    private function values(object $form, array $given, string $testEmail): array
    {
        $values = [];
        foreach ($form->getCustomFields() as $field) {
            $handle = $field->handle;
            if (array_key_exists($handle, $given)) {
                $values[$handle] = $given[$handle];
            } elseif ($field->required && !$field->getIsCosmetic()) {
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
            foreach ($field->options ?? [] as $option) {
                if (is_array($option) && empty($option['optgroup']) && empty($option['isOptgroup']) && ($option['value'] ?? '') !== '') {
                    return $option['value'];
                }
            }

            return null;
        };

        switch ((new \ReflectionClass($field))->getShortName()) {
            case 'Email':
                return $testEmail;
            case 'SingleLineText':
            case 'MultiLineText':
            case 'Hidden':
                return Plugin::TEST_VALUE;
            case 'Name':
                return ($field->useMultipleFields ?? false)
                    ? ['firstName' => 'Watchdog', 'lastName' => 'Test']
                    : Plugin::TEST_VALUE;
            case 'Number':
                return 1;
            case 'Agree':
                return true;
            case 'Phone':
                return '+32470000000';
            case 'Date':
                return new \DateTime();
            case 'Dropdown':
            case 'Radio':
                return $firstOption();
            case 'Checkboxes':
                $option = $firstOption();

                return $option !== null ? [$option] : null;
            default:
                // File uploads, element fields, nested fields, payments: left empty.
                return null;
        }
    }
}
