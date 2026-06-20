<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240018_FixRaceColumnNames extends AbstractMigration
{
    public function getDescription(): string { return 'Rename race columns to camelCase to match DefaultNamingStrategy'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race CHANGE distance_km distanceKm DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE race CHANGE is_done isDone TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE race CHANGE created_at createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race CHANGE distanceKm distance_km DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE race CHANGE isDone is_done TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE race CHANGE createdAt created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
