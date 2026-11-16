<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plan nutrition des scénarios de course : réglages et produits (raceNutritionPlan, un par scénario au plus) et lignes
 * manuelles d'un point ou d'un tronçon (raceNutritionItem) ; repère "assistance / sac d'allègement"
 * (raceCheckpoint.assistance) ; option du partage public (raceShare.showNutrition). Les deux colonnes ajoutées valent 0
 * pour les lignes existantes. Noms de tables en camelCase (MySQL est sensible à la casse en production), noms d'index
 * et de clés étrangères = ceux générés par Doctrine pour le mapping (doctrine:schema:update ne doit proposer aucune
 * différence sur ces tables).
 */
final class Version20240031_CreateRaceNutrition extends AbstractMigration
{
    public function getDescription(): string { return 'Create raceNutritionPlan and raceNutritionItem (race plan nutrition per scenario), add raceCheckpoint.assistance and raceShare.showNutrition'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE raceNutritionPlan (
            id            INT AUTO_INCREMENT NOT NULL,
            scenario_id   INT NOT NULL,
            carbsPerHour  INT NOT NULL,
            fluidPerHour  INT NOT NULL,
            mixDrink      INT NOT NULL,
            mixGel        INT NOT NULL,
            mixSolid      INT NOT NULL,
            solidEarly    TINYINT NOT NULL,
            flaskMl       INT NOT NULL,
            gelKey        VARCHAR(40) DEFAULT NULL,
            solidKey      VARCHAR(40) DEFAULT NULL,
            drinkKey      VARCHAR(40) DEFAULT NULL,
            products      JSON NOT NULL,
            createdAt     DATETIME NOT NULL,
            updatedAt     DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_B7CE9CB5E04E49DF (scenario_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_B7CE9CB5E04E49DF FOREIGN KEY (scenario_id) REFERENCES raceScenario (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE raceNutritionItem (
            id            INT AUTO_INCREMENT NOT NULL,
            plan_id       INT NOT NULL,
            checkpoint_id INT DEFAULT NULL,
            place         VARCHAR(10) NOT NULL,
            items         JSON NOT NULL,
            INDEX IDX_758FE2D6E899029B (plan_id),
            INDEX IDX_758FE2D6F27C615F (checkpoint_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_758FE2D6E899029B FOREIGN KEY (plan_id) REFERENCES raceNutritionPlan (id) ON DELETE CASCADE,
            CONSTRAINT FK_758FE2D6F27C615F FOREIGN KEY (checkpoint_id) REFERENCES raceCheckpoint (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE raceCheckpoint ADD assistance TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE raceShare ADD showNutrition TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raceShare DROP showNutrition');
        $this->addSql('ALTER TABLE raceCheckpoint DROP assistance');
        $this->addSql('DROP TABLE raceNutritionItem');
        $this->addSql('DROP TABLE raceNutritionPlan');
    }
}
