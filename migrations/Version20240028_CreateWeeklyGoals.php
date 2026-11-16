<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240028_CreateWeeklyGoals extends AbstractMigration
{
    public function getDescription(): string { return 'Create weeklyGoal (weekly goals per sport: distance, sessions, duration, elevation gain; one per user and sport)'; }

    public function up(Schema $schema): void
    {
        // L'objectif global (tous sports confondus) reste dans AthleteProfile : rien à migrer.
        // durationMinutes : saisie en heures dans les paramètres, stockée en minutes.
        $this->addSql('CREATE TABLE weeklyGoal (
            id              INT AUTO_INCREMENT NOT NULL,
            user_id         INT NOT NULL,
            sport_id        INT NOT NULL,
            distanceKm      DOUBLE PRECISION DEFAULT NULL,
            sessions        INT DEFAULT NULL,
            durationMinutes INT DEFAULT NULL,
            elevationGainM  INT DEFAULT NULL,
            position        INT NOT NULL,
            INDEX IDX_60F522FA76ED395 (user_id),
            INDEX IDX_60F522FAC78BCF8 (sport_id),
            UNIQUE INDEX uniq_weekly_goal_user_sport (user_id, sport_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_60F522FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_60F522FAC78BCF8 FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE weeklyGoal');
    }
}
