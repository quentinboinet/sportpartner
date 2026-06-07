<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240004_AddUserProfile extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create athlete_profiles table (1:1 with users)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE athlete_profiles (
            id            INT AUTO_INCREMENT NOT NULL,
            user_id       INT NOT NULL,
            handle        VARCHAR(50)        DEFAULT NULL,
            age           INT                DEFAULT NULL,
            weight        DOUBLE PRECISION   DEFAULT NULL,
            resting_hr    INT                DEFAULT NULL,
            max_hr        INT                DEFAULT NULL,
            vma           DOUBLE PRECISION   DEFAULT NULL,
            ftp           INT                DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX UNIQ_AP_USER (user_id),
            CONSTRAINT FK_AP_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE athlete_profiles');
    }
}
