<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240019_AddRaceDateAndElevation extends AbstractMigration
{
    public function getDescription(): string { return 'Add optional race date and elevation gain (m) to race'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race ADD raceDate DATE DEFAULT NULL, ADD elevationGain INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race DROP raceDate, DROP elevationGain');
    }
}
