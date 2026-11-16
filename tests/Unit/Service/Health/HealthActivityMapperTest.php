<?php

namespace App\Tests\Unit\Service\Health;

use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthExercise;
use App\Entity\HealthExerciseText;
use App\Entity\HealthSession;
use App\Entity\HealthSessionExercise;
use App\Entity\Sport;
use App\Entity\User;
use App\Service\Health\HealthActivityMapper;
use App\Service\Health\HealthSessionFactory;
use App\Service\Health\HealthSessionPayload;
use App\Service\Health\HealthSessionPayloadExercise;
use App\Service\Health\InvalidHealthPayloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Séance -> activité : règle du sport (renfo / autre, ou choix de l'utilisateur), nom par défaut, durées, départ
 * en UTC avec le fuseau du navigateur, activité locale (sans stravaId). Construction de la séance à partir de la
 * saisie (HealthSessionFactory) : copies depuis l'exercice, refus d'un exercice indisponible ou hors bornes.
 */
final class HealthActivityMapperTest extends TestCase
{
    /** @return iterable<string, array{list<HealthCategory>, ?string, string, string}> */
    public static function sportProvider(): iterable
    {
        $s = HealthCategory::Strength;
        $c = HealthCategory::Core;
        $b = HealthCategory::Balance;
        $m = HealthCategory::Mobility;
        $st = HealthCategory::Stretching;

        // catégories faites, choix, sport attendu, nature (nom par défaut)
        yield 'renfo seul'              => [[$s, $s, $c], null, 'weight_training', 'strength'];
        yield 'équilibre = renfo'       => [[$b], null, 'weight_training', 'strength'];
        yield 'mobilité seule'          => [[$m, $st], null, 'other', 'mobility'];
        yield 'égalité -> renfo'        => [[$s, $m], null, 'weight_training', 'mixed'];
        yield 'surtout mobilité'        => [[$c, $m, $st], null, 'other', 'mixed'];
        yield 'surtout renfo'           => [[$s, $c, $st], null, 'weight_training', 'mixed'];
        yield 'choix Autre respecté'    => [[$s, $s], 'other', 'other', 'strength'];
        yield 'choix Renfo respecté'    => [[$st], 'weight_training', 'weight_training', 'mobility'];
        yield 'choix inconnu ignoré'    => [[$st], 'run', 'other', 'mobility'];
    }

    /** @param list<HealthCategory> $categories */
    #[DataProvider('sportProvider')]
    public function testSportRuleAndSessionKind(array $categories, ?string $chosen, string $sport, string $kind): void
    {
        self::assertSame($sport, HealthActivityMapper::sportSlug($categories, $chosen));
        self::assertSame($kind, HealthActivityMapper::sessionKind($categories));
    }

    private static function exercise(int $id, string $name, HealthCategory $category, HealthDosageMode $mode = HealthDosageMode::Reps, bool $unilateral = false, ?User $owner = null): HealthExercise
    {
        $e = (new HealthExercise())->setUser($owner)->setSlug($owner ? null : 'slug-'.$id)->setCategory($category)->setDosageMode($mode)->setUnilateral($unilateral);
        $e->addText((new HealthExerciseText())->setLocale('fr')->setName($name.' FR')->setSteps(['a']));
        if ($owner === null) {
            $e->addText((new HealthExerciseText())->setLocale('en')->setName($name.' EN')->setSteps(['a']));
        }
        (new \ReflectionProperty(HealthExercise::class, 'id'))->setValue($e, $id);

        return $e;
    }

    /** @param list<HealthSessionPayloadExercise> $exercises */
    private static function payload(array $exercises, ?string $name = null, bool $addActivity = true): HealthSessionPayload
    {
        return new HealthSessionPayload($name, new \DateTimeImmutable('@1763571600'), 'Europe/Paris', 1500, 1620, 7, 'Bien', $addActivity, null, $exercises);
    }

    public function testFactoryBuildsTheSessionFromTheExercisesNotFromTheBrowser(): void
    {
        $user = new User();
        $exercises = [
            7  => self::exercise(7, 'Pont', HealthCategory::Strength),
            27 => self::exercise(27, 'Planche latérale', HealthCategory::Core, HealthDosageMode::Time, true),
            50 => self::exercise(50, 'Pigeon', HealthCategory::Stretching, HealthDosageMode::Time, true),
        ];
        $payload = self::payload([
            new HealthSessionPayloadExercise(7, 3, 12, 45, [12, 12, 10]),
            new HealthSessionPayloadExercise(27, 2, 25, 30, [25]),
            new HealthSessionPayloadExercise(50, 2, 30, 10, []),
        ]);
        $kinds = [];
        $session = HealthSessionFactory::create($user, $payload, $exercises, 'en', static function (string $kind) use (&$kinds): string {
            $kinds[] = $kind;

            return 'Default '.$kind;
        });

        self::assertSame($user, $session->getUser());
        self::assertSame('Default strength', $session->getName(), 'Pigeon non fait : séance de renfo');
        self::assertSame(['strength'], $kinds);
        self::assertSame('2025-11-19 17:00:00', $session->getStartedAtUtc()->format('Y-m-d H:i:s'));
        self::assertSame('2025-11-19 18:00', $session->getLocalStart()->format('Y-m-d H:i'));
        self::assertSame([1500, 1620, 7, 'Bien'], [$session->getDurationSeconds(), $session->getElapsedSeconds(), $session->getRpe(), $session->getNotes()]);
        $rows = $session->getExercises()->toArray();
        self::assertSame(['Pont EN', 'Planche latérale EN', 'Pigeon EN'], array_map(static fn (HealthSessionExercise $x): string => $x->getName(), $rows));
        self::assertSame([0, 1, 2], array_map(static fn (HealthSessionExercise $x): int => $x->getPosition(), $rows));
        self::assertSame([HealthDosageMode::Time, true, 2, 25, 30, [25]], [$rows[1]->getMode(), $rows[1]->isUnilateral(), $rows[1]->getSets(), $rows[1]->getValue(), $rows[1]->getRestSeconds(), $rows[1]->getPerformed()]);
        self::assertSame($exercises[27], $rows[1]->getExercise());
        self::assertSame(4, $session->countSetsDone());
        self::assertSame(34, $rows[0]->totalDone());
        self::assertSame([HealthCategory::Strength, HealthCategory::Core], HealthActivityMapper::doneCategories($session));
    }

    public function testCustomExerciseNameIsKeptInItsOwnLanguageAndAGivenNameWins(): void
    {
        $owner = new User();
        $exercises = [3 => self::exercise(3, 'Mon gainage', HealthCategory::Core, HealthDosageMode::Time, false, $owner)];
        $session = HealthSessionFactory::create($owner, self::payload([new HealthSessionPayloadExercise(3, 1, 20, 0, [20])], 'Ma séance'), $exercises, 'en', static fn (): string => 'x');

        self::assertSame('Ma séance', $session->getName());
        self::assertSame('Mon gainage FR', $session->getExercises()->first()->getName());
    }

    public function testUnavailableExerciseIsRefused(): void
    {
        $this->expectException(InvalidHealthPayloadException::class);
        try {
            HealthSessionFactory::create(new User(), self::payload([new HealthSessionPayloadExercise(99, 1, 10, 0, [10])]), [], 'fr', static fn (): string => 'x');
        } catch (InvalidHealthPayloadException $e) {
            self::assertSame('unknown_exercise', $e->errorCode);
            throw $e;
        }
    }

    /** @return iterable<string, array{HealthDosageMode, int, bool}> */
    public static function modeBoundsProvider(): iterable
    {
        yield 'répétitions 100'  => [HealthDosageMode::Reps, 100, true];
        yield 'répétitions 101'  => [HealthDosageMode::Reps, 101, false];
        yield 'secondes 5'       => [HealthDosageMode::Time, 5, true];
        yield 'secondes 4'       => [HealthDosageMode::Time, 4, false];
        yield 'secondes 600'     => [HealthDosageMode::Time, 600, true];
    }

    #[DataProvider('modeBoundsProvider')]
    public function testValueMustFitTheModeOfTheExercise(HealthDosageMode $mode, int $value, bool $ok): void
    {
        $exercises = [1 => self::exercise(1, 'X', HealthCategory::Strength, $mode)];
        try {
            HealthSessionFactory::create(new User(), self::payload([new HealthSessionPayloadExercise(1, 1, $value, 0, [$value])]), $exercises, 'fr', static fn (): string => 'x');
            self::assertTrue($ok);
        } catch (InvalidHealthPayloadException $e) {
            self::assertFalse($ok);
            self::assertSame('invalid_payload', $e->errorCode);
        }
    }

    public function testActivityMappedFromTheSession(): void
    {
        $user = new User();
        $sport = (new Sport())->setSlug('weight_training')->setIcon('bi-activity')->setColor('#8B5CF6');
        $session = (new HealthSession($user))->setName('Renfo du soir')->setStartedAt(new \DateTimeImmutable('2026-11-16 19:30:00', new \DateTimeZone('Europe/Paris')))
            ->setTimezone('Europe/Paris')->setDurationSeconds(1500)->setElapsedSeconds(1800);
        $activity = HealthActivityMapper::toActivity($session, $sport);

        self::assertSame($user, $activity->getUser());
        self::assertSame($sport, $activity->getSport());
        self::assertSame('Renfo du soir', $activity->getName());
        // Départ stocké en UTC, comme les activités Strava ; fuseau du navigateur pour le calendrier
        self::assertSame('2026-11-16 18:30:00', $activity->getStartDate()?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $activity->getStartDate()?->getTimezone()->getName());
        self::assertSame('Europe/Paris', $activity->getTimezone());
        self::assertSame([1500, 1800], [$activity->getMovingTimeSeconds(), $activity->getElapsedTimeSeconds()]);
        // Activité locale : pas de stravaId (la synchro Strava ne la touche jamais), pas de distance ni de matériel
        self::assertSame([null, null, null, null], [$activity->getStravaId(), $activity->getDistanceMeters(), $activity->getTotalElevationGain(), $activity->getGear()]);
    }

    public function testSessionStartIsReadBackInUtcWhateverThePhpTimezone(): void
    {
        $session = (new HealthSession(new User()))->setStartedAt(new \DateTimeImmutable('@1763571600'))->setTimezone('America/New_York');
        // Une date relue de la base arrive dans le fuseau de PHP : seule l'heure murale compte
        (new \ReflectionProperty(HealthSession::class, 'startedAt'))->setValue($session, new \DateTimeImmutable('2025-11-19 17:00:00', new \DateTimeZone('Asia/Tokyo')));
        self::assertSame('2025-11-19T17:00:00+00:00', $session->getStartedAtUtc()->format('c'));
        self::assertSame('2025-11-19 12:00', $session->getLocalStart()->format('Y-m-d H:i'));
        $session->setTimezone('Not/AZone');
        self::assertSame(date_default_timezone_get(), $session->getLocalStart()->getTimezone()->getName());
    }
}
