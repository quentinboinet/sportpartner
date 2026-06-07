<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240007_SyncColumnTypes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync remaining column type differences (datetime comments, sortOrder default)';
    }

    public function up(Schema $schema): void
    {
        // Doctrine ORM 3 / DBAL 3 no longer uses COMMENT for datetime_immutable on MySQL
        $this->addSql('ALTER TABLE activities
            CHANGE startDate startDate DATETIME DEFAULT NULL,
            CHANGE createdAt createdAt DATETIME NOT NULL
        ');

        // Remove DEFAULT 0 from sortOrder (Doctrine does not reflect PHP defaults as DB defaults)
        $this->addSql('ALTER TABLE sports CHANGE sortOrder sortOrder INT NOT NULL');

        $this->addSql('ALTER TABLE users
            CHANGE stravaTokenExpiresAt stravaTokenExpiresAt DATETIME DEFAULT NULL,
            CHANGE createdAt createdAt DATETIME NOT NULL,
            CHANGE updatedAt updatedAt DATETIME DEFAULT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE activities
            CHANGE startDate startDate DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            CHANGE createdAt createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'
        ");

        $this->addSql('ALTER TABLE sports CHANGE sortOrder sortOrder INT NOT NULL DEFAULT 0');

        $this->addSql("ALTER TABLE users
            CHANGE stravaTokenExpiresAt stravaTokenExpiresAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            CHANGE createdAt createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            CHANGE updatedAt updatedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        ");
    }
}
