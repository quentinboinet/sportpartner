<?php

namespace App\Tests\Unit\Migrations;

use App\Entity\RaceCheckpoint;
use App\Entity\RaceNutritionItem;
use App\Entity\RaceNutritionPlan;
use App\Entity\RaceScenario;
use App\Entity\RaceShare;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use DoctrineMigrations\Version20240031_CreateRaceNutrition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Migration Version20240031_CreateRaceNutrition : tables raceNutritionPlan et raceNutritionItem (camelCase, comme le
 * mapping : MySQL est sensible à la casse en production), clés étrangères en cascade, colonnes raceCheckpoint.assistance
 * et raceShare.showNutrition à 0 pour les lignes existantes ; down() défait exactement up().
 */
final class CreateRaceNutritionMigrationTest extends TestCase
{
    private Version20240031_CreateRaceNutrition $migration;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../migrations/Version20240031_CreateRaceNutrition.php';
        $this->migration = new Version20240031_CreateRaceNutrition($this->createStub(Connection::class), new NullLogger());
    }

    /** @return list<string> */
    private function sql(string $direction): array
    {
        $this->migration->{$direction}(new Schema());

        return array_map(static fn (Query $q): string => (string) preg_replace('/\s+/', ' ', $q->getStatement()), $this->migration->getSql());
    }

    #[Test]
    public function upCreatesBothTablesAndTheColumns(): void
    {
        $sql = $this->sql('up');

        self::assertCount(4, $sql);
        self::assertSame(
            'CREATE TABLE raceNutritionPlan ( id INT AUTO_INCREMENT NOT NULL, scenario_id INT NOT NULL, carbsPerHour INT NOT NULL, '
            .'fluidPerHour INT NOT NULL, mixDrink INT NOT NULL, mixGel INT NOT NULL, mixSolid INT NOT NULL, solidEarly TINYINT NOT NULL, '
            .'flaskMl INT NOT NULL, gelKey VARCHAR(40) DEFAULT NULL, solidKey VARCHAR(40) DEFAULT NULL, drinkKey VARCHAR(40) DEFAULT NULL, '
            .'products JSON NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME DEFAULT NULL, '
            .'UNIQUE INDEX UNIQ_B7CE9CB5E04E49DF (scenario_id), PRIMARY KEY (id), '
            .'CONSTRAINT FK_B7CE9CB5E04E49DF FOREIGN KEY (scenario_id) REFERENCES raceScenario (id) ON DELETE CASCADE '
            .') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
            $sql[0],
        );
        self::assertSame(
            'CREATE TABLE raceNutritionItem ( id INT AUTO_INCREMENT NOT NULL, plan_id INT NOT NULL, checkpoint_id INT DEFAULT NULL, '
            .'place VARCHAR(10) NOT NULL, items JSON NOT NULL, INDEX IDX_758FE2D6E899029B (plan_id), INDEX IDX_758FE2D6F27C615F (checkpoint_id), '
            .'PRIMARY KEY (id), CONSTRAINT FK_758FE2D6E899029B FOREIGN KEY (plan_id) REFERENCES raceNutritionPlan (id) ON DELETE CASCADE, '
            .'CONSTRAINT FK_758FE2D6F27C615F FOREIGN KEY (checkpoint_id) REFERENCES raceCheckpoint (id) ON DELETE CASCADE '
            .') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
            $sql[1],
        );
        self::assertSame('ALTER TABLE raceCheckpoint ADD assistance TINYINT DEFAULT 0 NOT NULL', $sql[2]);
        self::assertSame('ALTER TABLE raceShare ADD showNutrition TINYINT DEFAULT 0 NOT NULL', $sql[3]);
    }

    #[Test]
    public function downRevertsUp(): void
    {
        self::assertSame([
            'ALTER TABLE raceShare DROP showNutrition',
            'ALTER TABLE raceCheckpoint DROP assistance',
            'DROP TABLE raceNutritionItem',
            'DROP TABLE raceNutritionPlan',
        ], $this->sql('down'));
    }

    #[Test]
    public function entityMappingMatchesTheMigration(): void
    {
        $table = static fn (string $class): string => (new \ReflectionClass($class))->getAttributes(Table::class)[0]->newInstance()->name;
        self::assertSame('raceNutritionPlan', $table(RaceNutritionPlan::class));
        self::assertSame('raceNutritionItem', $table(RaceNutritionItem::class));
        self::assertSame('raceCheckpoint', $table(RaceCheckpoint::class));
        self::assertSame('raceShare', $table(RaceShare::class));

        $join = static fn (string $class, string $property): JoinColumn => (new \ReflectionProperty($class, $property))->getAttributes(JoinColumn::class)[0]->newInstance();
        $column = static fn (string $class, string $property): Column => (new \ReflectionProperty($class, $property))->getAttributes(Column::class)[0]->newInstance();

        // Un plan par scénario, supprimé avec lui ; lignes supprimées avec le plan et avec leur repère
        self::assertCount(1, (new \ReflectionProperty(RaceNutritionPlan::class, 'scenario'))->getAttributes(OneToOne::class));
        self::assertSame(RaceScenario::class, (string) (new \ReflectionProperty(RaceNutritionPlan::class, 'scenario'))->getType());
        self::assertTrue($join(RaceNutritionPlan::class, 'scenario')->unique);
        self::assertSame('CASCADE', $join(RaceNutritionPlan::class, 'scenario')->onDelete);
        self::assertCount(1, (new \ReflectionProperty(RaceNutritionItem::class, 'plan'))->getAttributes(ManyToOne::class));
        self::assertFalse($join(RaceNutritionItem::class, 'plan')->nullable);
        self::assertSame('CASCADE', $join(RaceNutritionItem::class, 'plan')->onDelete);
        self::assertTrue($join(RaceNutritionItem::class, 'checkpoint')->nullable, 'null = start point / finish leg');
        self::assertSame('CASCADE', $join(RaceNutritionItem::class, 'checkpoint')->onDelete);

        foreach (['gelKey', 'solidKey', 'drinkKey'] as $property) {
            self::assertSame(40, $column(RaceNutritionPlan::class, $property)->length);
            self::assertTrue($column(RaceNutritionPlan::class, $property)->nullable);
        }
        self::assertSame(10, $column(RaceNutritionItem::class, 'place')->length);
        self::assertSame(['default' => false], $column(RaceCheckpoint::class, 'assistance')->options);
        self::assertSame(['default' => false], $column(RaceShare::class, 'showNutrition')->options);

        // Défauts : pas d'assistance, nutrition non partagée
        self::assertFalse((new RaceCheckpoint())->hasAssistance());
        self::assertFalse((new RaceShare())->isShowNutrition());
        self::assertSame('p:start', (new RaceNutritionItem())->setPlace(RaceNutritionItem::PLACE_POINT)->rowKey());
        self::assertSame('s:finish', (new RaceNutritionItem())->rowKey());
    }
}
