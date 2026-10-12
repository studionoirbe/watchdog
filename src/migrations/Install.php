<?php

namespace studionoir\watchdog\migrations;

use craft\db\Migration;
use studionoir\watchdog\services\Mail;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->dropTableIfExists(Mail::TABLE);
        $this->createTable(Mail::TABLE, [
            'id' => $this->primaryKey(),
            'occurredAt' => $this->integer()->notNull(),
            // Form plugin that was sending the mail ("formie", "freeform"), if any.
            'origin' => $this->string(20),
            // Id under which that plugin's own log reports the same failure, e.g. "formie:412".
            'reportedAs' => $this->string(64),
            'form' => $this->string(),
            'submissionId' => $this->integer(),
            'subject' => $this->string(),
            'recipients' => $this->text(),
            'error' => $this->text(),
        ]);
        $this->createIndex(null, Mail::TABLE, ['occurredAt']);
        $this->createIndex(null, Mail::TABLE, ['reportedAs']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Mail::TABLE);

        return true;
    }
}
