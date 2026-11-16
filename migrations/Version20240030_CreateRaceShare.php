<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lien de partage public du plan de course (App\Entity\RaceShare, une ligne par course au plus) et message public
 * de chaque repère (raceCheckpoint.publicMessage, distinct des notes privées).
 * Noms d'index et de clés étrangères = ceux générés par Doctrine pour le mapping (doctrine:schema:update ne doit
 * proposer aucune différence sur ces tables).
 */
final class Version20240030_CreateRaceShare extends AbstractMigration
{
    public function getDescription(): string { return 'Create raceShare (public share link of a race plan, one per race) and add raceCheckpoint.publicMessage'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE raceShare (
            id            INT AUTO_INCREMENT NOT NULL,
            race_id       INT NOT NULL,
            scenario_id   INT DEFAULT NULL,
            token         VARCHAR(32) NOT NULL,
            enabled       TINYINT NOT NULL,
            displayName   VARCHAR(60) DEFAULT NULL,
            showPaces     TINYINT NOT NULL,
            showCutoffs   TINYINT NOT NULL,
            message       VARCHAR(1000) DEFAULT NULL,
            startMessage  VARCHAR(500) DEFAULT NULL,
            finishMessage VARCHAR(500) DEFAULT NULL,
            createdAt     DATETIME NOT NULL,
            updatedAt     DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_2E9FD20A6E59D40D (race_id),
            INDEX IDX_2E9FD20AE04E49DF (scenario_id),
            UNIQUE INDEX UNIQ_RACE_SHARE_TOKEN (token),
            PRIMARY KEY (id),
            CONSTRAINT FK_2E9FD20A6E59D40D FOREIGN KEY (race_id) REFERENCES race (id) ON DELETE CASCADE,
            CONSTRAINT FK_2E9FD20AE04E49DF FOREIGN KEY (scenario_id) REFERENCES raceScenario (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE raceCheckpoint ADD publicMessage VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raceCheckpoint DROP publicMessage');
        $this->addSql('DROP TABLE raceShare');
    }
}
