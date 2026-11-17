<?php

namespace App\Tests\Unit\Service\User;

use App\Entity\GearBrand;
use App\Entity\Sport;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;

/**
 * Audit de la suppression du compte (AccountDeleter ne supprime que la ligne users) : toute donnée de
 * l'utilisateur doit partir par ON DELETE CASCADE, et rien ne doit bloquer la suppression.
 *
 * - Mapping Doctrine : toute relation vers User est CASCADE ; toute relation vers une donnée de
 *   l'utilisateur est CASCADE ou SET NULL (colonne nullable) ; la liste des données supprimées est
 *   celle attendue (une nouvelle table rattachée à l'utilisateur fait échouer ce test : vérifier sa
 *   cascade, puis l'ajouter ici, dans AccountDeleter et dans le texte de la zone de danger).
 * - Migrations (ce qui est réellement en base, en prod comme en local) : même règle pour les clés
 *   étrangères créées par les up().
 */
final class AccountDeletionCoverageTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    /** Entités supprimées avec le compte (directement ou par leur parent). */
    private const OWNED = [
        'Activity', 'ActivityFuel', 'AthleteProfile', 'Gear', 'HealthExercise', 'HealthExerciseText',
        'HealthExerciseUserPref', 'HealthSession', 'HealthSessionExercise', 'Meal', 'Race', 'RaceCheckpoint',
        'RaceNutritionItem', 'RaceNutritionPlan', 'RaceRoute', 'RaceScenario', 'RaceScenarioSplit', 'RaceShare',
        'UserAvatar', 'WeeklyGoal',
    ];

    /** Tables des entités ci-dessus et de users, telles que nommées dans les migrations. */
    private const OWNED_TABLES = [
        'users', 'activities', 'activityFuel', 'AthleteProfile', 'athlete_profiles', 'gear', 'healthExercise',
        'healthExerciseText', 'healthExerciseUserPref', 'healthSession', 'healthSessionExercise', 'Meal', 'race',
        'raceCheckpoint', 'raceNutritionItem', 'raceNutritionPlan', 'raceRoute', 'raceScenario', 'raceScenarioSplit',
        'raceShare', 'userAvatar', 'weeklyGoal',
    ];

    /**
     * Clés étrangères du mapping (côté propriétaire des ManyToOne / OneToOne).
     *
     * @return list<array{from: string, property: string, to: string, onDelete: ?string, nullable: bool}>
     */
    private static function foreignKeys(): array
    {
        $keys  = [];
        $files = glob(self::ROOT.'/src/Entity/*.php');
        self::assertIsArray($files);
        foreach ($files as $file) {
            $class = 'App\\Entity\\'.basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            if ($ref->isEnum() || $ref->getAttributes(ORM\Entity::class) === []) {
                continue;
            }
            foreach ($ref->getProperties() as $property) {
                $assoc = ($property->getAttributes(ORM\ManyToOne::class)[0] ?? $property->getAttributes(ORM\OneToOne::class)[0] ?? null)?->newInstance();
                if ($assoc === null || ($assoc instanceof ORM\OneToOne && $assoc->mappedBy !== null)) {
                    continue;
                }
                $target = $assoc->targetEntity;
                if ($target === null) {
                    $type = $property->getType();
                    self::assertInstanceOf(\ReflectionNamedType::class, $type, $class.'::$'.$property->getName());
                    $target = $type->getName();
                }
                $join   = ($property->getAttributes(ORM\JoinColumn::class)[0] ?? null)?->newInstance();
                $keys[] = [
                    'from'     => $ref->getShortName(),
                    'property' => $property->getName(),
                    'to'       => (new \ReflectionClass($target))->getShortName(),
                    'onDelete' => $join?->onDelete !== null ? strtoupper($join->onDelete) : null,
                    'nullable' => $join?->nullable ?? true,
                ];
            }
        }

        return $keys;
    }

    /** @return list<string> Entités supprimées par cascade depuis User */
    private static function ownedEntities(): array
    {
        $keys  = self::foreignKeys();
        $owned = ['User' => true];
        do {
            $added = false;
            foreach ($keys as $key) {
                if ($key['onDelete'] === 'CASCADE' && isset($owned[$key['to']]) && !isset($owned[$key['from']])) {
                    $owned[$key['from']] = true;
                    $added = true;
                }
            }
        } while ($added);
        unset($owned['User']);
        $list = array_keys($owned);
        sort($list);

        return $list;
    }

    public function testEveryRelationToUserCascades(): void
    {
        $toUser = array_filter(self::foreignKeys(), static fn (array $key): bool => $key['to'] === 'User');
        $this->assertGreaterThanOrEqual(10, \count($toUser));
        foreach ($toUser as $key) {
            $this->assertSame('CASCADE', $key['onDelete'], $key['from'].'::$'.$key['property'].' -> User must be ON DELETE CASCADE');
        }
    }

    public function testNothingBlocksTheCascade(): void
    {
        $owned = array_flip(self::ownedEntities());
        $owned['User'] = true;
        foreach (self::foreignKeys() as $key) {
            if (!isset($owned[$key['to']])) {
                continue;
            }
            $label = $key['from'].'::$'.$key['property'].' -> '.$key['to'];
            $this->assertContains($key['onDelete'], ['CASCADE', 'SET NULL'], $label.' would block the account deletion');
            if ($key['onDelete'] === 'SET NULL') {
                $this->assertTrue($key['nullable'], $label.' is SET NULL on a NOT NULL column');
            }
        }
    }

    public function testOwnedDataIsTheExpectedList(): void
    {
        $expected = self::OWNED;
        sort($expected);
        $this->assertSame($expected, self::ownedEntities(), 'new data attached to the user: check its cascade, then update this list, AccountDeleter and the danger zone text');
    }

    public function testReferenceTablesAreNotOwned(): void
    {
        $owned = self::ownedEntities();
        foreach ([Sport::class, GearBrand::class, User::class] as $class) {
            $this->assertNotContains((new \ReflectionClass($class))->getShortName(), $owned);
        }
        // Les relations vers les tables de référence ne remontent jamais vers elles
        foreach (self::foreignKeys() as $key) {
            if (\in_array($key['from'], ['Sport', 'GearBrand'], true)) {
                $this->fail($key['from'].' must not reference other tables');
            }
        }
    }

    public function testMigrationForeignKeysMatch(): void
    {
        $files = glob(self::ROOT.'/migrations/*.php');
        self::assertIsArray($files);
        $toUsers = 0;
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $up   = substr($code, (int) strpos($code, 'function up'), (strpos($code, 'function down') ?: \strlen($code)) - (int) strpos($code, 'function up'));
            preg_match_all('/FOREIGN KEY \(`?(\w+)`?\) REFERENCES `?(\w+)`? \(`?id`?\)(?: ON DELETE (CASCADE|SET NULL|RESTRICT|NO ACTION))?/i', $up, $matches, \PREG_SET_ORDER);
            foreach ($matches as $m) {
                $table    = $m[2];
                $onDelete = strtoupper($m[3] ?? '');
                $label    = basename($file).': '.$m[1].' -> '.$table;
                if ($table === 'users') {
                    ++$toUsers;
                    $this->assertSame('CASCADE', $onDelete, $label.' must be ON DELETE CASCADE');
                } elseif (\in_array($table, self::OWNED_TABLES, true)) {
                    $this->assertContains($onDelete, ['CASCADE', 'SET NULL'], $label.' would block the account deletion');
                }
            }
        }
        // activities, athlete_profiles puis AthleteProfile, Meal, race, gear, userAvatar, weeklyGoal, healthExercise,
        // healthExerciseUserPref, healthSession
        $this->assertGreaterThanOrEqual(11, $toUsers);
    }
}
