<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240021_AddRaceType extends AbstractMigration
{
    public function getDescription(): string { return 'Add race type (road / trail) to race'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race ADD type VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race DROP type');
    }
}
