<?php

namespace App\Tests\Unit\Service\Activity;

use App\Entity\Activity;
use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Entity\Sport;
use App\Service\Activity\ManualActivityForm;
use App\Service\Health\FieldError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Saisie manuelle d'une activité : lecture, bornes, fuseau, futur refusé, matériel, application et rechargement. */
final class ManualActivityFormTest extends TestCase
{
    private const SPORTS = ['run', 'trail', 'ride', 'swim', 'walk', 'hike', 'weight_training', 'other'];
    /** Matériel proposé : 5 = chaussures, 7 = vélo. */
    private const GEAR = [5 => GearType::Shoes, 7 => GearType::Bike];

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function valid(array $override = []): array
    {
        return array_merge([
            'sport' => 'run', 'name' => "  Sortie   du\nmatin ", 'date' => '2026-11-16', 'time' => '07:30',
            'moving_h' => '0', 'moving_m' => '52', 'moving_s' => '30', 'elapsed_h' => '', 'elapsed_m' => '', 'elapsed_s' => '',
            'distance' => '10,5', 'elevation' => '120',
            'hr_avg' => '148', 'hr_max' => '171', 'gear' => 'auto', 'timezone' => 'Europe/Paris',
        ], $override);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string> champ => clé d'erreur
     */
    private static function errors(array $data, string $fallbackTz = 'UTC'): array
    {
        return array_map(static fn (FieldError $e): string => $e->key, ManualActivityForm::fromRequest($data, $fallbackTz)->validate(self::SPORTS, self::now(), self::GEAR));
    }

    /** @param array<string, mixed> $data */
    private static function validated(array $data, string $fallbackTz = 'UTC'): ManualActivityForm
    {
        $form = ManualActivityForm::fromRequest($data, $fallbackTz);
        self::assertSame([], array_map(static fn (FieldError $e): string => $e->key, $form->validate(self::SPORTS, self::now(), self::GEAR)));

        return $form;
    }

    #[Test]
    public function validInputIsReadInTheBrowserTimeZoneAndStoredInUtc(): void
    {
        $v = self::validated(self::valid())->values();

        self::assertSame('Sortie du matin', $v->name);
        self::assertSame('2026-11-16 06:30:00 UTC', $v->startUtc->format('Y-m-d H:i:s T'), 'Paris = UTC+1 en novembre');
        self::assertSame('Europe/Paris', $v->timezone);
        self::assertSame([3150, 3150], [$v->movingSeconds, $v->elapsedSeconds], 'temps écoulé vide = temps en mouvement');
        self::assertSame(10500.0, $v->distanceMeters);
        self::assertSame([120, 148, 171], [$v->elevationMeters, $v->hrAvg, $v->hrMax]);
        self::assertTrue($v->gearAuto);
        self::assertNull($v->gearId);
    }

    #[Test]
    public function summerTimeAndTimeZoneFallbacks(): void
    {
        $summer = self::validated(self::valid(['date' => '2026-07-01']))->values();
        self::assertSame('2026-07-01 05:30:00', $summer->startUtc->format('Y-m-d H:i:s'), 'Paris = UTC+2 en été');

        // Fuseau inconnu ou absent : fuseau de repli (celui de l'activité modifiée), sinon celui de PHP
        $form = ManualActivityForm::fromRequest(self::valid(['timezone' => 'Mars/Olympus', 'date' => '2026-11-15']), 'America/New_York');
        self::assertSame('America/New_York', $form->timezone);
        $form->validate(self::SPORTS, self::now(), self::GEAR);
        self::assertSame('2026-11-15 12:30:00', $form->values()->startUtc->format('Y-m-d H:i:s'));
        self::assertSame(date_default_timezone_get(), ManualActivityForm::fromRequest(self::valid(['timezone' => '']), 'nope')->timezone);
    }

    /**
     * @param array{string, string, string} $parts
     *
     * @return array<string, string>
     */
    private static function moving(array $parts): array
    {
        return ['moving_h' => $parts[0], 'moving_m' => $parts[1], 'moving_s' => $parts[2]];
    }

    /**
     * @param array{string, string, string} $parts
     *
     * @return array<string, string>
     */
    private static function elapsed(array $parts): array
    {
        return ['elapsed_h' => $parts[0], 'elapsed_m' => $parts[1], 'elapsed_s' => $parts[2]];
    }

    /** @return iterable<string, array{array{string, string, string}, int}> */
    public static function durations(): iterable
    {
        yield '45 min 30 s (et non 45 h 30)' => [['', '45', '30'], 2730];
        yield 'heures, minutes, secondes' => [['1', '5', '30'], 3930];
        yield 'zéros devant' => [['01', '05', '09'], 3909];
        yield 'heures et minutes, secondes vides' => [['1', '5', ''], 3900];
        yield 'secondes seules' => [['', '', '45'], 45];
        yield 'espaces autour' => [[' 2 ', ' 10', '5 '], 7805];
        yield '48 h tout juste' => [['48', '', ''], 172800];
        yield '48 h écrit 48:00:00' => [['48', '0', '0'], 172800];
    }

    /** @param array{string, string, string} $parts */
    #[Test]
    #[DataProvider('durations')]
    public function durationIsReadFromThreeFields(array $parts, int $seconds): void
    {
        self::assertSame($seconds, self::validated(self::valid(self::moving($parts) + ['distance' => '']))->values()->movingSeconds);
    }

    #[Test]
    public function elapsedTimeInThreeFields(): void
    {
        $v = self::validated(self::valid(self::elapsed(['', '55', ''])))->values();
        self::assertSame([3150, 3300], [$v->movingSeconds, $v->elapsedSeconds]);
        // Trois champs vides : égal au temps en mouvement ; égal : accepté
        self::assertSame(3150, self::validated(self::valid(self::elapsed(['', '', ''])))->values()->elapsedSeconds);
        self::assertSame(3150, self::validated(self::valid(self::elapsed(['0', '52', '30'])))->values()->elapsedSeconds);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, string>}> */
    public static function invalid(): iterable
    {
        yield 'sport inconnu' => [['sport' => 'curling'], ['sport' => 'activity_form.error.sport']];
        yield 'nom trop long' => [['name' => str_repeat('a', 256)], ['name' => 'activity_form.error.name_too_long']];
        yield 'date impossible' => [['date' => '2026-02-30'], ['date' => 'activity_form.error.date']];
        yield 'date vide' => [['date' => ''], ['date' => 'activity_form.error.date']];
        yield 'avant 2000' => [['date' => '1999-12-31'], ['date' => 'activity_form.error.date_min']];
        yield 'heure vide' => [['time' => ''], ['time' => 'activity_form.error.time']];
        yield 'heure 24:00' => [['time' => '24:00'], ['time' => 'activity_form.error.time']];
        yield 'demain' => [['date' => '2026-11-17'], ['date' => 'activity_form.error.future']];
        yield "aujourd'hui plus tard" => [['time' => '13:06'], ['time' => 'activity_form.error.future']];
        yield 'temps manquant' => [self::moving(['', '', '']), ['moving' => 'activity_form.error.duration_required']];
        yield 'temps nul' => [self::moving(['0', '0', '0']), ['moving' => 'activity_form.error.duration_range']];
        yield 'plus de 48 h' => [self::moving(['48', '0', '1']), ['moving' => 'activity_form.error.duration_range']];
        yield 'heures > 48' => [self::moving(['49', '', '']), ['moving' => 'activity_form.error.duration_parts']];
        yield 'minutes > 59' => [self::moving(['', '75', '']), ['moving' => 'activity_form.error.duration_parts']];
        yield 'secondes > 59' => [self::moving(['', '45', '60']), ['moving' => 'activity_form.error.duration_parts']];
        yield 'texte' => [self::moving(['', '45:30', '']), ['moving' => 'activity_form.error.duration_parts']];
        yield 'décimale' => [self::moving(['', '45.5', '']), ['moving' => 'activity_form.error.duration_parts']];
        yield 'négatif' => [self::moving(['', '-5', '']), ['moving' => 'activity_form.error.duration_parts']];
        yield 'trois chiffres' => [self::moving(['', '', '100']), ['moving' => 'activity_form.error.duration_parts']];
        yield 'écoulé invalide' => [self::elapsed(['', 'x', '']), ['elapsed' => 'activity_form.error.duration_parts']];
        yield 'écoulé < mouvement' => [self::elapsed(['', '50', '']), ['elapsed' => 'activity_form.error.elapsed_lt_moving']];
        yield 'écoulé nul' => [self::elapsed(['0', '0', '0']), ['elapsed' => 'activity_form.error.duration_range']];
        yield 'écoulé > 48 h' => [self::elapsed(['48', '30', '']), ['elapsed' => 'activity_form.error.duration_range']];
        yield 'distance nulle' => [['distance' => '0'], ['distance' => 'activity_form.error.distance']];
        yield 'distance > 1000 km' => [['distance' => '1000,001'], ['distance' => 'activity_form.error.distance']];
        yield 'distance 4 décimales' => [['distance' => '1,2345'], ['distance' => 'activity_form.error.distance']];
        yield 'distance négative' => [['distance' => '-5'], ['distance' => 'activity_form.error.distance']];
        yield 'D+ > 20 000' => [['elevation' => '20001'], ['elevation' => 'activity_form.error.elevation']];
        yield 'D+ décimal' => [['elevation' => '12.5'], ['elevation' => 'activity_form.error.elevation']];
        yield 'FC trop basse' => [['hr_avg' => '29'], ['hr_avg' => 'activity_form.error.hr']];
        yield 'FC trop haute' => [['hr_max' => '251'], ['hr_max' => 'activity_form.error.hr']];
        yield 'FC moyenne > max' => [['hr_avg' => '180', 'hr_max' => '170'], ['hr_max' => 'activity_form.error.hr_order']];
        yield 'matériel inconnu' => [['gear' => '9'], ['gear' => 'activity_form.error.gear']];
        yield 'vélo pour une course' => [['gear' => '7'], ['gear' => 'activity_form.error.gear']];
        yield 'matériel illisible' => [['gear' => 'abc'], ['gear' => 'activity_form.error.gear']];
    }

    /**
     * @param array<string, mixed>  $override
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidInputGivesOneErrorOnTheField(array $override, array $expected): void
    {
        self::assertSame($expected, self::errors(self::valid($override)));
    }

    #[Test]
    public function boundsAreInclusive(): void
    {
        $v = self::validated(self::valid([
            'distance' => '1000', 'elevation' => '20000', 'hr_avg' => '30', 'hr_max' => '250',
            'date' => '2000-01-01', 'time' => '0:00',
        ] + self::moving(['48', '', '']) + self::elapsed(['48', '0', '0'])))->values();
        self::assertSame([1000000.0, 20000, 30, 250, 172800, 172800], [$v->distanceMeters, $v->elevationMeters, $v->hrAvg, $v->hrMax, $v->movingSeconds, $v->elapsedSeconds]);

        // Futur toléré 5 min : 13:05 à Paris = 12:05 UTC
        self::assertSame([], self::errors(self::valid(['time' => '13:05'])));
        // Distance exacte au mètre, sans flottant
        self::assertSame(21097.0, self::validated(self::valid(['distance' => '21,097']))->values()->distanceMeters);
        self::assertSame(10500.0, self::validated(self::valid(['distance' => '10.5']))->values()->distanceMeters);
        // Heure « 7h05 » et heure avec secondes
        self::assertSame('06:05:00', self::validated(self::valid(['time' => '7h05']))->values()->startUtc->format('H:i:s'));
        self::assertSame('06:05:09', self::validated(self::valid(['time' => '07:05:09']))->values()->startUtc->format('H:i:s'));
    }

    #[Test]
    public function sportsWithoutDistanceOrElevationIgnoreThoseFields(): void
    {
        $strength = self::validated(self::valid(['sport' => 'weight_training', 'distance' => 'n/a', 'elevation' => 'n/a', 'gear' => '']))->values();
        self::assertNull($strength->distanceMeters);
        self::assertNull($strength->elevationMeters);

        $swim = self::validated(self::valid(['sport' => 'swim', 'distance' => '1,5', 'elevation' => 'n/a', 'gear' => '']))->values();
        self::assertSame(1500.0, $swim->distanceMeters);
        self::assertNull($swim->elevationMeters);

        // Champs facultatifs vides
        $bare = self::validated(self::valid(['distance' => '', 'elevation' => '', 'hr_avg' => '', 'hr_max' => '', 'name' => '']))->values();
        self::assertSame([null, null, null, null, ''], [$bare->distanceMeters, $bare->elevationMeters, $bare->hrAvg, $bare->hrMax, $bare->name]);
    }

    #[Test]
    public function gearChoices(): void
    {
        self::assertSame(5, self::validated(self::valid(['gear' => '5']))->values()->gearId);
        $none = self::validated(self::valid(['gear' => '']))->values();
        self::assertSame([false, null], [$none->gearAuto, $none->gearId]);
        self::assertSame(7, self::validated(self::valid(['sport' => 'ride', 'gear' => '7']))->values()->gearId);
        // « Autre » accepte tous les types
        self::assertSame(7, self::validated(self::valid(['sport' => 'other', 'gear' => '7']))->values()->gearId);
        // Natation : aucun matériel compatible
        self::assertSame(['gear' => 'activity_form.error.gear'], self::errors(self::valid(['sport' => 'swim', 'gear' => '5'])));
    }

    #[Test]
    public function valuesBeforeASuccessfulValidationThrow(): void
    {
        $form = ManualActivityForm::fromRequest(self::valid(self::moving(['', '', ''])), 'UTC');
        $form->validate(self::SPORTS, self::now(), self::GEAR);
        $this->expectException(\LogicException::class);
        $form->values();
    }

    #[Test]
    public function applyToFillsTheActivityAndClearsStravaOnlyFields(): void
    {
        $sport = (new Sport())->setSlug('run');
        $gear = (new Gear())->setType(GearType::Shoes)->setModel('Pegasus 40')->setBrand((new GearBrand())->setName('Nike'));
        $activity = (new Activity())->setAverageSpeed(9.9)->setAverageCadence(80.0);

        self::validated(self::valid())->applyTo($activity, $sport, $gear, 'Course du 16/11/2026');
        self::assertSame('Sortie du matin', $activity->getName());
        self::assertSame($sport, $activity->getSport());
        self::assertSame($gear, $activity->getGear());
        self::assertSame('2026-11-16 06:30:00', $activity->getStartDateUtc()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-16 07:30', $activity->getLocalStartDate()?->format('Y-m-d H:i'));
        self::assertSame('Europe/Paris', $activity->getTimezone());
        self::assertSame([3150, 3150, 10500.0, 120.0, 148.0, 171.0], [
            $activity->getMovingTimeSeconds(), $activity->getElapsedTimeSeconds(), $activity->getDistanceMeters(),
            $activity->getTotalElevationGain(), $activity->getAverageHeartrate(), $activity->getMaxHeartrate(),
        ]);
        self::assertNull($activity->getAverageSpeed(), 'vitesse calculée avec distance / temps');
        self::assertNull($activity->getAverageCadence());
        self::assertNull($activity->getStravaId());
        self::assertSame('5:00', $activity->getPaceMinPerKm());

        // Nom vide : nom par défaut ; champs vidés : null
        self::validated(self::valid(['name' => '   ', 'distance' => '', 'hr_avg' => '', 'hr_max' => '', 'elevation' => '']))->applyTo($activity, $sport, null, 'Course du 16/11/2026');
        self::assertSame('Course du 16/11/2026', $activity->getName());
        self::assertSame([null, null, null, null, null], [$activity->getDistanceMeters(), $activity->getAverageHeartrate(), $activity->getMaxHeartrate(), $activity->getTotalElevationGain(), $activity->getGear()]);
    }

    #[Test]
    public function fromActivityReloadsTheEntryInItsTimeZone(): void
    {
        $sport = (new Sport())->setSlug('ride');
        $activity = (new Activity())->setSport($sport)->setName('Tour du lac')
            ->setStartDate(new \DateTimeImmutable('2026-11-16 06:30:00', new \DateTimeZone('UTC')))->setTimezone('Europe/Paris')
            ->setMovingTimeSeconds(5430)->setElapsedTimeSeconds(5430)->setDistanceMeters(42195.0)
            ->setTotalElevationGain(350.0)->setAverageHeartrate(141.4)->setMaxHeartrate(165.0);

        $fr = ManualActivityForm::fromActivity($activity, 'fr');
        self::assertSame(['ride', 'Tour du lac', '2026-11-16', '07:30', '1', '30', '30', '', '', '', '42,195', '350', '141', '165', '', 'Europe/Paris'],
            [$fr->sport, $fr->name, $fr->date, $fr->time, $fr->movingH, $fr->movingM, $fr->movingS, $fr->elapsedH, $fr->elapsedM, $fr->elapsedS,
             $fr->distance, $fr->elevation, $fr->hrAvg, $fr->hrMax, $fr->gear, $fr->timezone]);
        self::assertSame('42.195', ManualActivityForm::fromActivity($activity, 'en')->distance);
        self::assertSame('10', ManualActivityForm::fromActivity((clone $activity)->setDistanceMeters(10000.0), 'fr')->distance);
        $longer = ManualActivityForm::fromActivity((clone $activity)->setElapsedTimeSeconds(7205), 'fr');
        self::assertSame(['2', '0', '5'], [$longer->elapsedH, $longer->elapsedM, $longer->elapsedS]);
        $short = ManualActivityForm::fromActivity((clone $activity)->setMovingTimeSeconds(2730)->setElapsedTimeSeconds(2730), 'fr');
        self::assertSame(['0', '45', '30'], [$short->movingH, $short->movingM, $short->movingS], '45 min 30 s rechargé tel quel');

        // Rechargé tel quel, il redonne la même activité
        $form = ManualActivityForm::fromRequest([
            'sport' => $fr->sport, 'name' => $fr->name, 'date' => $fr->date, 'time' => $fr->time,
            'distance' => $fr->distance, 'elevation' => $fr->elevation, 'hr_avg' => $fr->hrAvg,
            'hr_max' => $fr->hrMax, 'gear' => $fr->gear,
        ] + self::moving([$fr->movingH, $fr->movingM, $fr->movingS]) + self::elapsed([$fr->elapsedH, $fr->elapsedM, $fr->elapsedS]), $fr->timezone);
        self::assertSame([], $form->validate(self::SPORTS, self::now(), self::GEAR));
        self::assertSame(['2026-11-16 06:30:00', 5430, 5430, 42195.0], [$form->values()->startUtc->format('Y-m-d H:i:s'), $form->values()->movingSeconds, $form->values()->elapsedSeconds, $form->values()->distanceMeters]);
    }

    #[Test]
    public function createStartsTodayInTheGivenTimeZone(): void
    {
        $late = new \DateTimeImmutable('2026-11-16 23:30:00', new \DateTimeZone('UTC'));
        $form = ManualActivityForm::create($late, 'Europe/Paris');
        self::assertSame(['2026-11-17', 'run', 'auto', 'Europe/Paris'], [$form->date, $form->sport, $form->gear, $form->timezone]);
        self::assertSame(date_default_timezone_get(), ManualActivityForm::create($late, 'nope')->timezone);
    }
}
