<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240014_AddSplitsMetric extends AbstractMigration
{
    public function getDescription(): string { return 'Add splitsMetric (JSON Strava splits_metric) to activities table'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities ADD splitsMetric TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities DROP COLUMN splitsMetric');
    }
}
