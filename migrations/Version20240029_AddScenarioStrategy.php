<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stratégie d'allure des scénarios (App\Entity\PacingStrategy / PacingIntensity). Les valeurs par défaut
 * donnent 'even' / 'medium' aux scénarios existants : leurs temps auto restent exactement les mêmes.
 */
final class Version20240029_AddScenarioStrategy extends AbstractMigration
{
    public function getDescription(): string { return 'Add pacing strategy and intensity to raceScenario (existing rows: even / medium)'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE raceScenario ADD strategy VARCHAR(20) DEFAULT 'even' NOT NULL, ADD strategyIntensity VARCHAR(10) DEFAULT 'medium' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raceScenario DROP strategy, DROP strategyIntensity');
    }
}
