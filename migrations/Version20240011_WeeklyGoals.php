<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240011_WeeklyGoals extends AbstractMigration
{
    public function getDescription(): string { return 'Add weeklyDistanceGoalKm and weeklySessionsGoal to athleteprofile'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE AthleteProfile ADD weeklyDistanceGoalKm INT DEFAULT NULL, ADD weeklySessionsGoal INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE AthleteProfile DROP COLUMN weeklyDistanceGoalKm, DROP COLUMN weeklySessionsGoal');
    }
}
