<?php

namespace App\Tests\Unit\Migrations;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
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
use Doctrine\ORM\Mapping\UniqueConstraint;
use DoctrineMigrations\Version20240030_CreateRaceShare;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Migration Version20240030_CreateRaceShare : table raceShare (camelCase, comme le mapping : MySQL est sensible à la
 * casse en production), clés étrangères (course : CASCADE, scénario : SET NULL), jeton unique, et colonne
 * raceCheckpoint.publicMessage ; down() défait exactement up().
 */
final class CreateRaceShareMigrationTest extends TestCase
{
    private Version20240030_CreateRaceShare $migration;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../migrations/Version20240030_CreateRaceShare.php';
        $this->migration = new Version20240030_CreateRaceShare($this->createStub(Connection::class), new NullLogger());
    }

    /** @return list<string> */
    private function sql(string $direction): array
    {
        $this->migration->{$direction}(new Schema());

        return array_map(static fn (Query $q): string => (string) preg_replace('/\s+/', ' ', $q->getStatement()), $this->migration->getSql());
    }

    #[Test]
    public function upCreatesTheTableAndTheColumn(): void
    {
        $sql = $this->sql('up');

        self::assertCount(2, $sql);
        self::assertSame(
            'CREATE TABLE raceShare ( id INT AUTO_INCREMENT NOT NULL, race_id INT NOT NULL, scenario_id INT DEFAULT NULL, '
            .'token VARCHAR(32) NOT NULL, enabled TINYINT NOT NULL, displayName VARCHAR(60) DEFAULT NULL, '
            .'showPaces TINYINT NOT NULL, showCutoffs TINYINT NOT NULL, message VARCHAR(1000) DEFAULT NULL, '
            .'startMessage VARCHAR(500) DEFAULT NULL, finishMessage VARCHAR(500) DEFAULT NULL, createdAt DATETIME NOT NULL, '
            .'updatedAt DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_2E9FD20A6E59D40D (race_id), INDEX IDX_2E9FD20AE04E49DF (scenario_id), '
            .'UNIQUE INDEX UNIQ_RACE_SHARE_TOKEN (token), PRIMARY KEY (id), '
            .'CONSTRAINT FK_2E9FD20A6E59D40D FOREIGN KEY (race_id) REFERENCES race (id) ON DELETE CASCADE, '
            .'CONSTRAINT FK_2E9FD20AE04E49DF FOREIGN KEY (scenario_id) REFERENCES raceScenario (id) ON DELETE SET NULL '
            .') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
            $sql[0],
        );
        self::assertSame('ALTER TABLE raceCheckpoint ADD publicMessage VARCHAR(500) DEFAULT NULL', $sql[1]);
    }

    #[Test]
    public function downRevertsUp(): void
    {
        self::assertSame(['ALTER TABLE raceCheckpoint DROP publicMessage', 'DROP TABLE raceShare'], $this->sql('down'));
    }

    #[Test]
    public function entityMappingMatchesTheMigration(): void
    {
        $class = new \ReflectionClass(RaceShare::class);
        self::assertSame('raceShare', $class->getAttributes(Table::class)[0]->newInstance()->name);
        $unique = $class->getAttributes(UniqueConstraint::class)[0]->newInstance();
        self::assertSame('UNIQ_RACE_SHARE_TOKEN', $unique->name);
        self::assertSame(['token'], $unique->columns);

        $column = static fn (string $class, string $property): Column => (new \ReflectionProperty($class, $property))->getAttributes(Column::class)[0]->newInstance();
        $join = static fn (string $property): JoinColumn => (new \ReflectionProperty(RaceShare::class, $property))->getAttributes(JoinColumn::class)[0]->newInstance();

        self::assertSame(32, $column(RaceShare::class, 'token')->length);
        self::assertSame(60, $column(RaceShare::class, 'displayName')->length);
        self::assertSame(RaceShare::MAX_MESSAGE_LENGTH, $column(RaceShare::class, 'message')->length);
        self::assertSame(1000, RaceShare::MAX_MESSAGE_LENGTH);
        foreach (['startMessage', 'finishMessage'] as $property) {
            self::assertSame(500, $column(RaceShare::class, $property)->length);
            self::assertTrue($column(RaceShare::class, $property)->nullable);
        }
        self::assertSame(500, RaceShare::MAX_POINT_MESSAGE_LENGTH);
        self::assertSame(500, $column(RaceCheckpoint::class, 'publicMessage')->length);
        self::assertTrue($column(RaceCheckpoint::class, 'publicMessage')->nullable);
        self::assertSame('raceCheckpoint', (new \ReflectionClass(RaceCheckpoint::class))->getAttributes(Table::class)[0]->newInstance()->name);

        // Une ligne par course, supprimée avec elle ; scénario supprimé -> NULL (repli sur le premier scénario)
        $race = (new \ReflectionProperty(RaceShare::class, 'race'))->getAttributes(OneToOne::class);
        self::assertCount(1, $race);
        self::assertSame(Race::class, (string) (new \ReflectionProperty(RaceShare::class, 'race'))->getType());
        self::assertTrue($join('race')->unique);
        self::assertFalse($join('race')->nullable);
        self::assertSame('CASCADE', $join('race')->onDelete);
        self::assertCount(1, (new \ReflectionProperty(RaceShare::class, 'scenario'))->getAttributes(ManyToOne::class));
        self::assertSame('?'.RaceScenario::class, (string) (new \ReflectionProperty(RaceShare::class, 'scenario'))->getType());
        self::assertTrue($join('scenario')->nullable);
        self::assertSame('SET NULL', $join('scenario')->onDelete);

        // Défauts d'un nouveau partage : désactivé, allures masquées, barrières affichées
        $share = new RaceShare();
        self::assertFalse($share->isEnabled());
        self::assertFalse($share->isShowPaces());
        self::assertTrue($share->isShowCutoffs());
    }
}
