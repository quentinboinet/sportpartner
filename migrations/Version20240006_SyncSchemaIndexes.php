<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240006_SyncSchemaIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync index names and column types after default naming strategy switch';
    }

    public function up(Schema $schema): void
    {
        // Drop the manually-named unique index on activities.stravaId
        // (no longer declared as unique on the entity — manual index from migration 2)
        $this->addSql('DROP INDEX UNIQ_STRAVA_ID ON activities');

        // Fix datetime_immutable COMMENT annotations (preserved by Doctrine for type mapping)
        $this->addSql('ALTER TABLE activities
            CHANGE startDate startDate DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            CHANGE createdAt createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'
        ');

        $this->addSql('ALTER TABLE users
            CHANGE stravaTokenExpiresAt stravaTokenExpiresAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            CHANGE emailVerified emailVerified TINYINT(1) NOT NULL,
            CHANGE createdAt createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            CHANGE updatedAt updatedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'
        ');

        $this->addSql('ALTER TABLE sports CHANGE sortOrder sortOrder INT NOT NULL DEFAULT 0');

        // Rename indexes to Doctrine-generated names
        $this->addSql('ALTER TABLE activities RENAME INDEX idx_user TO IDX_B5F1AFE5A76ED395');
        $this->addSql('ALTER TABLE activities RENAME INDEX idx_activity_sport TO IDX_B5F1AFE5AC78BCF8');
        $this->addSql('ALTER TABLE athlete_profiles RENAME INDEX uniq_ap_user TO UNIQ_56423B95A76ED395');
        $this->addSql('ALTER TABLE sports RENAME INDEX uniq_sport_slug TO UNIQ_73C9F91C989D9B62');
        $this->addSql('ALTER TABLE users RENAME INDEX uniq_email TO UNIQ_1483A5E9E7927C74');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STRAVA_ID ON activities (stravaId)');

        $this->addSql('ALTER TABLE activities RENAME INDEX IDX_B5F1AFE5A76ED395 TO idx_user');
        $this->addSql('ALTER TABLE activities RENAME INDEX IDX_B5F1AFE5AC78BCF8 TO idx_activity_sport');
        $this->addSql('ALTER TABLE athlete_profiles RENAME INDEX UNIQ_56423B95A76ED395 TO uniq_ap_user');
        $this->addSql('ALTER TABLE sports RENAME INDEX UNIQ_73C9F91C989D9B62 TO uniq_sport_slug');
        $this->addSql('ALTER TABLE users RENAME INDEX UNIQ_1483A5E9E7927C74 TO uniq_email');
    }
}
