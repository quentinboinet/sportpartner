<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240010_StravaIdBigint extends AbstractMigration
{
    public function getDescription(): string { return 'Change stravaId from INT to BIGINT (Strava IDs exceed 32-bit range)'; }

    public function up(Schema $schema): void
    {
        // Clear corrupted rows (all clamped to 2147483647) so they can be re-imported cleanly.
        $this->addSql('UPDATE activities SET stravaId = NULL WHERE stravaId = 2147483647');
        $this->addSql('ALTER TABLE activities MODIFY stravaId BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities MODIFY stravaId INT DEFAULT NULL');
    }
}
