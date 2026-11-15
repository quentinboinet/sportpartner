<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240022_AddRaceRouteSourceUrl extends AbstractMigration
{
    public function getDescription(): string { return 'Add source URL to raceRoute (GPX imported from a link)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raceRoute ADD sourceUrl VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raceRoute DROP sourceUrl');
    }
}
