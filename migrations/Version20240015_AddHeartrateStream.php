<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240015_AddHeartrateStream extends AbstractMigration
{
    public function getDescription(): string { return 'Add heartrateStream (JSON Strava HR stream) to activities table'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities ADD heartrateStream TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities DROP COLUMN heartrateStream');
    }
}
