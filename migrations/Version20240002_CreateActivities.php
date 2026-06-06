<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240002_CreateActivities extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create activities table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE activities (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            strava_id INT DEFAULT NULL,
            type VARCHAR(50) NOT NULL DEFAULT \'Run\',
            name VARCHAR(255) DEFAULT NULL,
            distance_meters DOUBLE PRECISION DEFAULT NULL,
            moving_time_seconds INT DEFAULT NULL,
            elapsed_time_seconds INT DEFAULT NULL,
            total_elevation_gain DOUBLE PRECISION DEFAULT NULL,
            average_heartrate DOUBLE PRECISION DEFAULT NULL,
            max_heartrate DOUBLE PRECISION DEFAULT NULL,
            average_cadence DOUBLE PRECISION DEFAULT NULL,
            average_speed DOUBLE PRECISION DEFAULT NULL,
            start_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            timezone VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_USER (user_id),
            UNIQUE INDEX UNIQ_STRAVA_ID (strava_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_ACTIVITY_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activities');
    }
}
