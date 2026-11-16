<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240025_AddGearStravaId extends AbstractMigration
{
    public function getDescription(): string { return 'Add gear.stravaGearId (gear imported from Strava, unique per user) and gear.stravaLastActivityId'; }

    public function up(Schema $schema): void
    {
        // NULL pour le matériel saisi à la main : MySQL accepte plusieurs NULL dans un index unique
        $this->addSql('ALTER TABLE gear ADD stravaGearId VARCHAR(32) DEFAULT NULL, ADD stravaLastActivityId BIGINT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_GEAR_USER_STRAVA ON gear (user_id, stravaGearId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_GEAR_USER_STRAVA ON gear');
        $this->addSql('ALTER TABLE gear DROP stravaGearId, DROP stravaLastActivityId');
    }
}
