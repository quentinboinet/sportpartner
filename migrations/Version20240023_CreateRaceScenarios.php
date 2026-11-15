<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240023_CreateRaceScenarios extends AbstractMigration
{
    public function getDescription(): string { return 'Create raceScenario (race plan target scenarios) and raceScenarioSplit (target arrival time per checkpoint)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE raceScenario (
            id            INT AUTO_INCREMENT NOT NULL,
            race_id       INT NOT NULL,
            name          VARCHAR(60) NOT NULL,
            startTime     TIME DEFAULT NULL,
            targetSeconds INT DEFAULT NULL,
            position      INT NOT NULL,
            createdAt     DATETIME NOT NULL,
            updatedAt     DATETIME DEFAULT NULL,
            INDEX IDX_ACCB9E836E59D40D (race_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_ACCB9E836E59D40D FOREIGN KEY (race_id) REFERENCES race (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE raceScenarioSplit (
            id             INT AUTO_INCREMENT NOT NULL,
            scenario_id    INT NOT NULL,
            checkpoint_id  INT NOT NULL,
            elapsedSeconds INT NOT NULL,
            INDEX IDX_44A56384E04E49DF (scenario_id),
            INDEX IDX_44A56384F27C615F (checkpoint_id),
            UNIQUE INDEX UNIQ_RACE_SCENARIO_SPLIT (scenario_id, checkpoint_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_44A56384E04E49DF FOREIGN KEY (scenario_id) REFERENCES raceScenario (id) ON DELETE CASCADE,
            CONSTRAINT FK_44A56384F27C615F FOREIGN KEY (checkpoint_id) REFERENCES raceCheckpoint (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE raceScenarioSplit');
        $this->addSql('DROP TABLE raceScenario');
    }
}
