<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240012_AddPolylineToActivities extends AbstractMigration
{
    public function getDescription(): string { return 'Add summaryPolyline (TEXT) to activities table for Leaflet map rendering'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities ADD summaryPolyline TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities DROP COLUMN summaryPolyline');
    }
}
