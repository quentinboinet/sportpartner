<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240001_CreateUsers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            roles JSON NOT NULL,
            password VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) DEFAULT NULL,
            strava_access_token VARCHAR(255) DEFAULT NULL,
            strava_refresh_token VARCHAR(255) DEFAULT NULL,
            strava_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            strava_athlete_id INT DEFAULT NULL,
            stripe_customer_id VARCHAR(255) DEFAULT NULL,
            stripe_subscription_id VARCHAR(255) DEFAULT NULL,
            subscription_plan VARCHAR(20) NOT NULL DEFAULT \'free\',
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            email_verification_token VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_EMAIL (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
