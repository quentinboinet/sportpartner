<?php

namespace App\Tests\Unit\Migrations;

use App\Entity\PacingIntensity;
use App\Entity\PacingStrategy;
use App\Entity\RaceScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use Doctrine\ORM\Mapping\Column;
use DoctrineMigrations\Version20240029_AddScenarioStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Migration Version20240029_AddScenarioStrategy : les scénarios existants doivent recevoir 'even' / 'medium'
 * (temps auto inchangés), et le mapping Doctrine doit déclarer les mêmes défauts (sinon doctrine:schema:update
 * proposerait une différence).
 */
final class AddScenarioStrategyMigrationTest extends TestCase
{
    private Version20240029_AddScenarioStrategy $migration;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../migrations/Version20240029_AddScenarioStrategy.php';
        $this->migration = new Version20240029_AddScenarioStrategy($this->createStub(Connection::class), new NullLogger());
    }

    /** @return list<string> */
    private function sql(string $direction): array
    {
        $this->migration->{$direction}(new Schema());

        return array_map(static fn (Query $q): string => $q->getStatement(), $this->migration->getSql());
    }

    #[Test]
    public function upAddsBothColumnsWithDefaultsForExistingRows(): void
    {
        self::assertSame(
            ["ALTER TABLE raceScenario ADD strategy VARCHAR(20) DEFAULT 'even' NOT NULL, ADD strategyIntensity VARCHAR(10) DEFAULT 'medium' NOT NULL"],
            $this->sql('up'),
        );
        // Les défauts SQL sont ceux qui laissent les scénarios existants inchangés
        self::assertSame('even', PacingStrategy::Even->value);
        self::assertSame('medium', PacingIntensity::Medium->value);
    }

    #[Test]
    public function downDropsBothColumns(): void
    {
        self::assertSame(['ALTER TABLE raceScenario DROP strategy, DROP strategyIntensity'], $this->sql('down'));
    }

    #[Test]
    public function entityMappingMatchesTheMigration(): void
    {
        $columns = [];
        foreach (['strategy', 'strategyIntensity'] as $property) {
            $attributes = (new \ReflectionProperty(RaceScenario::class, $property))->getAttributes(Column::class);
            self::assertCount(1, $attributes);
            $columns[$property] = $attributes[0]->newInstance();
        }

        self::assertSame(20, $columns['strategy']->length);
        self::assertSame(['default' => 'even'], $columns['strategy']->options);
        self::assertFalse($columns['strategy']->nullable);
        self::assertSame(PacingStrategy::class, $columns['strategy']->enumType);
        self::assertSame(10, $columns['strategyIntensity']->length);
        self::assertSame(['default' => 'medium'], $columns['strategyIntensity']->options);
        self::assertFalse($columns['strategyIntensity']->nullable);
        self::assertSame(PacingIntensity::class, $columns['strategyIntensity']->enumType);

        // Toutes les valeurs tiennent dans leur colonne
        foreach (PacingStrategy::cases() as $strategy) {
            self::assertLessThanOrEqual(20, \strlen($strategy->value));
        }
        foreach (PacingIntensity::cases() as $intensity) {
            self::assertLessThanOrEqual(10, \strlen($intensity->value));
        }

        // Nouveau scénario sans stratégie choisie : mêmes défauts que les lignes migrées
        $scenario = new RaceScenario();
        self::assertSame(PacingStrategy::Even, $scenario->getStrategy());
        self::assertSame(PacingIntensity::Medium, $scenario->getStrategyIntensity());
    }
}
