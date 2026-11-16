<?php

namespace App\Tests\Unit\Migrations;

use App\Entity\Activity;
use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthEquipment;
use App\Entity\HealthExercise;
use App\Entity\HealthExerciseText;
use App\Entity\HealthExerciseUserPref;
use App\Entity\HealthLevel;
use App\Entity\HealthSession;
use App\Entity\HealthSessionExercise;
use App\Service\Health\HealthLimits;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use DoctrineMigrations\Version20240032_CreateHealth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Migration Version20240032_CreateHealth : tables de l'espace Santé en camelCase (comme le mapping : MySQL est
 * sensible à la casse en production), clés étrangères et cascades, exercices fournis insérés avec leurs textes
 * français ET anglais (contenu vérifié : langues, énumérations, bornes, longueurs, tirets), down() défait up().
 */
final class CreateHealthMigrationTest extends TestCase
{
    private const FILE = __DIR__.'/../../../migrations/Version20240032_CreateHealth.php';
    private const TEXT_FIELDS = ['name', 'muscles', 'start', 'steps', 'breathing', 'mistakes', 'easier', 'harder'];
    /** Tiret cadratin, demi-cadratin et signe moins typographique : interdits dans le projet (CLAUDE.md). */
    private const FORBIDDEN_DASHES = ["\u{2014}", "\u{2013}", "\u{2212}"];

    private Version20240032_CreateHealth $migration;

    protected function setUp(): void
    {
        require_once self::FILE;
        $this->migration = new Version20240032_CreateHealth($this->createStub(Connection::class), new NullLogger());
    }

    /** @return list<Query> */
    private function queries(string $direction): array
    {
        $this->migration->{$direction}(new Schema());

        return $this->migration->getSql();
    }

    private static function sql(Query $query): string
    {
        return (string) preg_replace('/\s+/', ' ', $query->getStatement());
    }

    /** @return array<string, array<string, mixed>> */
    private static function exercises(): array
    {
        return Version20240032_CreateHealth::EXERCISES;
    }

    // ── Schéma ────────────────────────────────────────────────────────

    #[Test]
    public function upCreatesTheFiveTablesThenSeedsEveryExerciseWithTwoLanguages(): void
    {
        $queries = $this->queries('up');
        $count = count(self::exercises());

        self::assertCount(5 + 3 * $count, $queries);
        self::assertSame(
            'CREATE TABLE healthExercise ( id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, slug VARCHAR(60) DEFAULT NULL, '
            .'category VARCHAR(12) NOT NULL, level VARCHAR(12) NOT NULL, bodyAreas JSON NOT NULL, equipment JSON NOT NULL, '
            .'unilateral TINYINT NOT NULL, dosageMode VARCHAR(5) NOT NULL, defaultSets SMALLINT NOT NULL, defaultValue INT NOT NULL, '
            .'defaultRestSeconds INT NOT NULL, position INT NOT NULL, videoUrl VARCHAR(500) DEFAULT NULL, createdAt DATETIME NOT NULL, '
            .'updatedAt DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_BE4DCB70989D9B62 (slug), INDEX IDX_BE4DCB70A76ED395 (user_id), '
            .'PRIMARY KEY (id), CONSTRAINT FK_BE4DCB70A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE '
            .') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
            self::sql($queries[0]),
        );
        self::assertSame(
            'CREATE TABLE healthExerciseText ( id INT AUTO_INCREMENT NOT NULL, exercise_id INT NOT NULL, locale VARCHAR(5) NOT NULL, '
            .'name VARCHAR(80) NOT NULL, targetMuscles VARCHAR(160) DEFAULT NULL, startPosition VARCHAR(500) DEFAULT NULL, '
            .'steps JSON NOT NULL, breathing VARCHAR(255) DEFAULT NULL, mistakes JSON NOT NULL, easier VARCHAR(300) DEFAULT NULL, '
            .'harder VARCHAR(300) DEFAULT NULL, INDEX IDX_4727E07E934951A (exercise_id), '
            .'UNIQUE INDEX UNIQ_HEALTH_EXERCISE_TEXT_LOCALE (exercise_id, locale), PRIMARY KEY (id), '
            .'CONSTRAINT FK_4727E07E934951A FOREIGN KEY (exercise_id) REFERENCES healthExercise (id) ON DELETE CASCADE '
            .') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
            self::sql($queries[1]),
        );
        self::assertStringStartsWith('CREATE TABLE healthExerciseUserPref (', self::sql($queries[2]));
        self::assertStringContainsString('UNIQUE INDEX UNIQ_HEALTH_PREF_USER_EXERCISE (user_id, exercise_id)', self::sql($queries[2]));
        self::assertStringContainsString('FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE', self::sql($queries[2]));
        self::assertStringContainsString('FOREIGN KEY (exercise_id) REFERENCES healthExercise (id) ON DELETE CASCADE', self::sql($queries[2]));
        self::assertStringStartsWith('CREATE TABLE healthSession (', self::sql($queries[3]));
        self::assertStringContainsString('UNIQUE INDEX UNIQ_9D20E34E81C06096 (activity_id)', self::sql($queries[3]));
        self::assertStringContainsString('INDEX IDX_HEALTH_SESSION_USER_STARTED (user_id, startedAt)', self::sql($queries[3]));
        self::assertStringContainsString('FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE', self::sql($queries[3]));
        self::assertStringContainsString('FOREIGN KEY (activity_id) REFERENCES activities (id) ON DELETE SET NULL', self::sql($queries[3]));
        self::assertStringStartsWith('CREATE TABLE healthSessionExercise (', self::sql($queries[4]));
        self::assertStringContainsString('FOREIGN KEY (session_id) REFERENCES healthSession (id) ON DELETE CASCADE', self::sql($queries[4]));
        self::assertStringContainsString('FOREIGN KEY (exercise_id) REFERENCES healthExercise (id) ON DELETE SET NULL', self::sql($queries[4]));
    }

    #[Test]
    public function seedInsertsBindTheExerciseDataInOrder(): void
    {
        $queries = array_slice($this->queries('up'), 5);
        $slugs = array_keys(self::exercises());

        foreach ($slugs as $i => $slug) {
            $exercise = self::exercises()[$slug];
            $insert = $queries[3 * $i];
            self::assertStringStartsWith('INSERT INTO healthExercise (user_id, slug, category, level, bodyAreas, equipment, unilateral, dosageMode, defaultSets, defaultValue, defaultRestSeconds, position, videoUrl, createdAt, updatedAt) VALUES (NULL, ', self::sql($insert));
            self::assertSame(
                [$slug, $exercise['category'], $exercise['level'], json_encode($exercise['areas']), json_encode($exercise['equipment']),
                    (int) $exercise['unilateral'], $exercise['dosage'][0], $exercise['dosage'][1], $exercise['dosage'][2], $exercise['dosage'][3],
                    ($i + 1) * 10, '2026-11-16 00:00:00'],
                $insert->getParameters(),
                $slug,
            );
            foreach (['fr' => 1, 'en' => 2] as $locale => $offset) {
                $text = $queries[3 * $i + $offset];
                self::assertSame('INSERT INTO healthExerciseText (exercise_id, locale, name, targetMuscles, startPosition, steps, breathing, mistakes, easier, harder) SELECT id, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM healthExercise WHERE slug = ?', self::sql($text));
                $params = $text->getParameters();
                $source = $exercise[$locale];
                self::assertSame($locale, $params[0]);
                self::assertSame($source['name'], $params[1]);
                self::assertSame($source['steps'], json_decode($params[4], true, 4, \JSON_THROW_ON_ERROR), "$slug.$locale steps");
                self::assertSame($source['mistakes'], json_decode($params[6], true, 4, \JSON_THROW_ON_ERROR), "$slug.$locale mistakes");
                self::assertSame($slug, $params[9]);
                // Accents gardés tels quels dans le JSON (pas de é)
                self::assertStringNotContainsString('\\u00', $params[4]);
            }
        }
    }

    #[Test]
    public function downDropsTheTablesInReverseOrder(): void
    {
        self::assertSame(
            ['DROP TABLE healthSessionExercise', 'DROP TABLE healthSession', 'DROP TABLE healthExerciseUserPref', 'DROP TABLE healthExerciseText', 'DROP TABLE healthExercise'],
            array_map(self::sql(...), $this->queries('down')),
        );
    }

    #[Test]
    public function entityMappingMatchesTheMigration(): void
    {
        $table = static fn (string $class): string => (string) (new \ReflectionClass($class))->getAttributes(Table::class)[0]->newInstance()->name;
        self::assertSame('healthExercise', $table(HealthExercise::class));
        self::assertSame('healthExerciseText', $table(HealthExerciseText::class));
        self::assertSame('healthExerciseUserPref', $table(HealthExerciseUserPref::class));
        self::assertSame('healthSession', $table(HealthSession::class));
        self::assertSame('healthSessionExercise', $table(HealthSessionExercise::class));

        $unique = static fn (string $class): UniqueConstraint => (new \ReflectionClass($class))->getAttributes(UniqueConstraint::class)[0]->newInstance();
        self::assertSame(['UNIQ_HEALTH_EXERCISE_TEXT_LOCALE', ['exercise_id', 'locale']], [$unique(HealthExerciseText::class)->name, $unique(HealthExerciseText::class)->columns]);
        self::assertSame(['UNIQ_HEALTH_PREF_USER_EXERCISE', ['user_id', 'exercise_id']], [$unique(HealthExerciseUserPref::class)->name, $unique(HealthExerciseUserPref::class)->columns]);
        $index = (new \ReflectionClass(HealthSession::class))->getAttributes(Index::class)[0]->newInstance();
        self::assertSame(['IDX_HEALTH_SESSION_USER_STARTED', ['user_id', 'startedAt']], [$index->name, $index->columns]);

        $join = static fn (string $class, string $property): JoinColumn => (new \ReflectionProperty($class, $property))->getAttributes(JoinColumn::class)[0]->newInstance();
        self::assertSame(['CASCADE', true], [$join(HealthExercise::class, 'user')->onDelete, $join(HealthExercise::class, 'user')->nullable]);
        self::assertSame('CASCADE', $join(HealthExerciseText::class, 'exercise')->onDelete);
        self::assertSame('CASCADE', $join(HealthExerciseUserPref::class, 'user')->onDelete);
        self::assertSame('CASCADE', $join(HealthExerciseUserPref::class, 'exercise')->onDelete);
        self::assertSame('CASCADE', $join(HealthSession::class, 'user')->onDelete);
        self::assertSame(['SET NULL', true, true], [$join(HealthSession::class, 'activity')->onDelete, $join(HealthSession::class, 'activity')->nullable, $join(HealthSession::class, 'activity')->unique]);
        self::assertCount(1, (new \ReflectionProperty(HealthSession::class, 'activity'))->getAttributes(OneToOne::class));
        self::assertSame('?'.Activity::class, (string) (new \ReflectionProperty(HealthSession::class, 'activity'))->getType());
        // Pas de relation inverse sur Activity (ni Strava ni la liste des activités ne chargent les séances)
        self::assertFalse((new \ReflectionClass(Activity::class))->hasProperty('healthSession'));
        self::assertSame('CASCADE', $join(HealthSessionExercise::class, 'session')->onDelete);
        self::assertSame(['SET NULL', true], [$join(HealthSessionExercise::class, 'exercise')->onDelete, $join(HealthSessionExercise::class, 'exercise')->nullable]);

        $column = static fn (string $class, string $property): Column => (new \ReflectionProperty($class, $property))->getAttributes(Column::class)[0]->newInstance();
        self::assertSame([60, true, true], [$column(HealthExercise::class, 'slug')->length, $column(HealthExercise::class, 'slug')->unique, $column(HealthExercise::class, 'slug')->nullable]);
        self::assertSame(HealthLimits::VIDEO_URL_MAX, $column(HealthExercise::class, 'videoUrl')->length);
        self::assertSame(HealthLimits::VIDEO_URL_MAX, $column(HealthExerciseUserPref::class, 'videoUrl')->length);
        self::assertSame(HealthExerciseText::NAME_MAX, $column(HealthExerciseText::class, 'name')->length);
        self::assertSame(HealthExerciseText::MUSCLES_MAX, $column(HealthExerciseText::class, 'targetMuscles')->length);
        self::assertSame(HealthExerciseText::START_MAX, $column(HealthExerciseText::class, 'startPosition')->length);
        self::assertSame(HealthExerciseText::BREATHING_MAX, $column(HealthExerciseText::class, 'breathing')->length);
        self::assertSame(HealthExerciseText::VARIATION_MAX, $column(HealthExerciseText::class, 'easier')->length);
        self::assertSame(HealthLimits::SESSION_NAME_MAX, $column(HealthSession::class, 'name')->length);
        self::assertSame(HealthLimits::NOTES_MAX, $column(HealthSession::class, 'notes')->length);
        self::assertSame(HealthLimits::SESSION_NAME_MAX, $column(HealthSessionExercise::class, 'name')->length);
        // Les valeurs des énumérations tiennent dans leurs colonnes
        foreach ([HealthCategory::cases(), HealthLevel::cases()] as $cases) {
            foreach ($cases as $case) {
                self::assertLessThanOrEqual(12, strlen($case->value));
            }
        }
        foreach (HealthDosageMode::cases() as $case) {
            self::assertLessThanOrEqual(5, strlen($case->value));
        }
    }

    // ── Contenu des exercices fournis ─────────────────────────────────

    #[Test]
    public function providedExercisesCoverEveryCategoryWithAboutSixtyEntries(): void
    {
        $byCategory = [];
        foreach (self::exercises() as $exercise) {
            $byCategory[$exercise['category']] = ($byCategory[$exercise['category']] ?? 0) + 1;
        }

        self::assertGreaterThanOrEqual(50, count(self::exercises()));
        self::assertLessThanOrEqual(60, count(self::exercises()));
        foreach (HealthCategory::cases() as $category) {
            self::assertGreaterThanOrEqual(5, $byCategory[$category->value] ?? 0, $category->value);
        }
        // Chaque matériel proposé dans les filtres est utilisé au moins une fois
        $equipment = array_unique(array_merge(...array_column(self::exercises(), 'equipment')));
        foreach (HealthEquipment::cases() as $case) {
            self::assertContains($case->value, $equipment, $case->value);
        }
        // Les classiques demandés pour les coureurs et cyclistes sont là
        foreach (['calf-raise', 'eccentric-heel-drop', 'tibialis-raise', 'short-foot', 'single-leg-balance', 'glute-bridge', 'single-leg-glute-bridge',
            'clamshell', 'lateral-band-walk', 'side-plank', 'front-plank', 'dead-bug', 'bird-dog', 'copenhagen-plank', 'split-squat',
            'bulgarian-split-squat', 'step-up', 'reverse-lunge', 'single-leg-rdl', 'hip-thrust', 'wall-sit', 'nordic-curl', 'hip-flexor-stretch',
            'hip-90-90', 'pigeon', 'open-book', 'cat-cow', 'ankle-knee-to-wall', 'hamstring-stretch', 'calf-stretch', 'worlds-greatest-stretch'] as $slug) {
            self::assertArrayHasKey($slug, self::exercises(), $slug);
        }
    }

    #[Test]
    public function everyExerciseHasValidStructuredData(): void
    {
        foreach (self::exercises() as $slug => $exercise) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
            self::assertLessThanOrEqual(60, strlen($slug));
            self::assertSame(['category', 'level', 'areas', 'equipment', 'unilateral', 'dosage', 'fr', 'en'], array_keys($exercise), $slug);
            self::assertNotNull(HealthCategory::tryFrom($exercise['category']), "$slug category");
            self::assertNotNull(HealthLevel::tryFrom($exercise['level']), "$slug level");
            self::assertNotEmpty($exercise['areas'], "$slug areas");
            self::assertSame($exercise['areas'], array_values(array_unique($exercise['areas'])), "$slug areas unique");
            foreach ($exercise['areas'] as $area) {
                self::assertNotNull(HealthBodyArea::tryFrom($area), "$slug area $area");
            }
            self::assertSame($exercise['equipment'], array_values(array_unique($exercise['equipment'])), "$slug equipment unique");
            foreach ($exercise['equipment'] as $equipment) {
                self::assertNotNull(HealthEquipment::tryFrom($equipment), "$slug equipment $equipment");
            }
            self::assertIsBool($exercise['unilateral']);

            [$mode, $sets, $value, $rest] = $exercise['dosage'];
            $dosageMode = HealthDosageMode::tryFrom($mode);
            self::assertNotNull($dosageMode, "$slug mode");
            self::assertGreaterThanOrEqual(HealthLimits::SETS_MIN, $sets, "$slug sets");
            self::assertLessThanOrEqual(HealthLimits::SETS_MAX, $sets, "$slug sets");
            self::assertGreaterThanOrEqual($dosageMode->minValue(), $value, "$slug value");
            self::assertLessThanOrEqual($dosageMode->maxValue(), $value, "$slug value");
            self::assertGreaterThanOrEqual(HealthLimits::REST_MIN, $rest, "$slug rest");
            self::assertLessThanOrEqual(HealthLimits::REST_MAX, $rest, "$slug rest");
        }
    }

    #[Test]
    public function everyExerciseHasEveryTextInFrenchAndEnglishWithinTheLimits(): void
    {
        $limits = [
            'name' => HealthExerciseText::NAME_MAX, 'muscles' => HealthExerciseText::MUSCLES_MAX, 'start' => HealthExerciseText::START_MAX,
            'breathing' => HealthExerciseText::BREATHING_MAX, 'easier' => HealthExerciseText::VARIATION_MAX, 'harder' => HealthExerciseText::VARIATION_MAX,
        ];
        $names = ['fr' => [], 'en' => []];
        foreach (self::exercises() as $slug => $exercise) {
            foreach (['fr', 'en'] as $locale) {
                $text = $exercise[$locale];
                self::assertSame(self::TEXT_FIELDS, array_keys($text), "$slug.$locale fields");
                foreach ($limits as $field => $max) {
                    self::assertIsString($text[$field]);
                    self::assertNotSame('', trim($text[$field]), "$slug.$locale.$field empty");
                    self::assertSame(trim($text[$field]), $text[$field], "$slug.$locale.$field spaces");
                    self::assertLessThanOrEqual($max, mb_strlen($text[$field]), "$slug.$locale.$field too long");
                }
                self::assertGreaterThanOrEqual(2, count($text['steps']), "$slug.$locale steps");
                self::assertLessThanOrEqual(HealthExerciseText::STEPS_MAX, count($text['steps']), "$slug.$locale steps");
                self::assertGreaterThanOrEqual(1, count($text['mistakes']), "$slug.$locale mistakes");
                self::assertLessThanOrEqual(HealthExerciseText::MISTAKES_MAX, count($text['mistakes']), "$slug.$locale mistakes");
                foreach ($text['steps'] as $step) {
                    self::assertNotSame('', trim($step));
                    self::assertLessThanOrEqual(HealthExerciseText::STEP_MAX, mb_strlen($step), "$slug.$locale step");
                }
                foreach ($text['mistakes'] as $mistake) {
                    self::assertNotSame('', trim($mistake));
                    self::assertLessThanOrEqual(HealthExerciseText::MISTAKE_MAX, mb_strlen($mistake), "$slug.$locale mistake");
                }
                $names[$locale][] = mb_strtolower($text['name']);
            }
            // Même nombre d'étapes et d'erreurs dans les deux langues (traductions, pas deux textes différents)
            self::assertCount(count($exercise['fr']['steps']), $exercise['en']['steps'], "$slug steps fr/en");
            self::assertCount(count($exercise['fr']['mistakes']), $exercise['en']['mistakes'], "$slug mistakes fr/en");
            self::assertNotSame($exercise['fr']['start'], $exercise['en']['start'], "$slug start translated");
        }
        foreach ($names as $locale => $list) {
            self::assertSame($list, array_values(array_unique($list)), "unique $locale names");
        }
    }

    #[Test]
    public function noForbiddenDashAnywhereInTheMigration(): void
    {
        $source = (string) file_get_contents(self::FILE);
        foreach (self::FORBIDDEN_DASHES as $dash) {
            self::assertStringNotContainsString($dash, $source);
        }
        $data = self::exercises();
        $strings = 0;
        array_walk_recursive($data, static function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                ++$strings;
                foreach (self::FORBIDDEN_DASHES as $dash) {
                    self::assertStringNotContainsString($dash, $value);
                }
            }
        });
        self::assertGreaterThan(1000, $strings);
    }
}
