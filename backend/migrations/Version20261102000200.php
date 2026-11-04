<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared per-user upload rate windows (T17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_rate_limits (owner_id INT NOT NULL PRIMARY KEY, window_started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, attempts INT NOT NULL, CONSTRAINT fk_import_rate_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT chk_import_rate_attempts CHECK(attempts > 0))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_rate_limits');
    }
}
