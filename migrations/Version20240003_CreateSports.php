<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240003_CreateSports extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sports reference table and migrate activities.type to FK';
    }

    public function up(Schema $schema): void
    {
        // 1. Créer la table sports
        $this->addSql("CREATE TABLE sports (
            id INT AUTO_INCREMENT NOT NULL,
            slug VARCHAR(50) NOT NULL,
            icon VARCHAR(60) NOT NULL,
            color VARCHAR(7) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE INDEX UNIQ_SPORT_SLUG (slug),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // 2. Insérer les sports de référence
        $this->addSql("INSERT INTO sports (slug, icon, color, sort_order) VALUES
            ('run',            'bi-person-walking', '#E8400C', 1),
            ('trail',          'bi-tree',           '#F59E0B', 2),
            ('ride',           'bi-bicycle',        '#06B6D4', 3),
            ('swim',           'bi-water',          '#3B82F6', 4),
            ('walk',           'bi-person-walking', '#22C55E', 5),
            ('hike',           'bi-mountains',      '#10B981', 6),
            ('weight_training','bi-activity',       '#8B5CF6', 7),
            ('other',          'bi-lightning-fill', '#9CA3AF', 8)
        ");

        // 3. Ajouter la colonne FK nullable dans activities
        $this->addSql("ALTER TABLE activities ADD COLUMN sport_id INT DEFAULT NULL");
        $this->addSql("ALTER TABLE activities ADD CONSTRAINT FK_ACTIVITY_SPORT FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE SET NULL");
        $this->addSql("CREATE INDEX IDX_ACTIVITY_SPORT ON activities (sport_id)");

        // 4. Migrer les données : type string → sport_id
        $this->addSql("UPDATE activities SET sport_id = (
            SELECT id FROM sports WHERE slug =
            CASE type
                WHEN 'Run'            THEN 'run'
                WHEN 'Trail'          THEN 'trail'
                WHEN 'TrailRun'       THEN 'trail'
                WHEN 'Ride'           THEN 'ride'
                WHEN 'VirtualRide'    THEN 'ride'
                WHEN 'Swim'           THEN 'swim'
                WHEN 'Walk'           THEN 'walk'
                WHEN 'Hike'           THEN 'hike'
                WHEN 'WeightTraining' THEN 'weight_training'
                WHEN 'Workout'        THEN 'weight_training'
                ELSE 'other'
            END
            LIMIT 1
        )");

        // 5. Supprimer l'ancienne colonne type
        $this->addSql("ALTER TABLE activities DROP COLUMN type");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE activities DROP FOREIGN KEY FK_ACTIVITY_SPORT");
        $this->addSql("ALTER TABLE activities DROP INDEX IDX_ACTIVITY_SPORT");
        $this->addSql("ALTER TABLE activities DROP COLUMN sport_id");
        $this->addSql("ALTER TABLE activities ADD COLUMN type VARCHAR(50) NOT NULL DEFAULT 'Run'");
        $this->addSql("DROP TABLE sports");
    }
}
