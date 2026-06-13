<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240016_AddWeatherData extends AbstractMigration
{
    public function getDescription(): string { return 'Add weatherData (JSON Open-Meteo snapshot) to activities table'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities ADD weatherData TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities DROP COLUMN weatherData');
    }
}
