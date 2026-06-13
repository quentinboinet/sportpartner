<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240013_AddElevationProfile extends AbstractMigration
{
    public function getDescription(): string { return 'Add elevationProfile (JSON altitude stream) to activities table'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities ADD elevationProfile TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities DROP COLUMN elevationProfile');
    }
}
