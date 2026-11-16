<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Sport;
use App\Entity\WeeklyGoal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

class WeeklyGoalTest extends TestCase
{
    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /** @return list<string> "chemin => clé du message" */
    private static function violations(WeeklyGoal $goal): array
    {
        $out = [];
        /** @var ConstraintViolationInterface $violation */
        foreach (self::validator()->validate($goal) as $violation) {
            $out[] = $violation->getPropertyPath().' => '.$violation->getMessageTemplate();
        }

        return $out;
    }

    private static function goal(): WeeklyGoal
    {
        return (new WeeklyGoal())->setSport((new Sport())->setSlug('run')->setIcon('bi-x')->setColor('#000000'));
    }

    public function testAtLeastOneTargetIsRequired(): void
    {
        self::assertSame([' => settings.goals.error.no_target'], self::violations(self::goal()));

        foreach (['setDistanceKm' => 10.0, 'setSessions' => 3, 'setDurationMinutes' => 90, 'setElevationGainM' => 500] as $setter => $value) {
            self::assertSame([], self::violations(self::goal()->$setter($value)), $setter.' suffit');
        }
    }

    public function testSportIsRequired(): void
    {
        self::assertSame([' => settings.goals.error.sport_required'], self::violations((new WeeklyGoal())->setSessions(2)));
    }

    public function testBounds(): void
    {
        $goal = self::goal()->setDistanceKm(0.04)->setSessions(31)->setDurationMinutes(6001)->setElevationGainM(0);

        self::assertSame([
            'distanceKm => settings.goals.error.distance_range',
            'sessions => settings.goals.error.sessions_range',
            'durationMinutes => settings.goals.error.duration_range',
            'elevationGainM => settings.goals.error.elevation_range',
        ], self::violations($goal));

        $limits = self::goal()->setDistanceKm(2000)->setSessions(1)->setDurationMinutes(6000)->setElevationGainM(50000);
        self::assertSame([], self::violations($limits), 'bornes incluses');
    }

    public function testDistanceIsRoundedToOneDecimalAndTargetsCopied(): void
    {
        $goal = self::goal()->setDistanceKm(12.345);
        self::assertSame(12.3, $goal->getDistanceKm());

        $copy = self::goal()->setSessions(4)->copyTargetsFrom((new WeeklyGoal())->setDurationMinutes(60));
        self::assertNull($copy->getSessions());
        self::assertSame(60, $copy->getDurationMinutes());
        self::assertTrue($copy->hasTarget());
    }

    /** Chaque message de l'objectif par sport existe en français et en anglais (domaine "messages"). */
    public function testMessagesAreTranslatedInBothLanguages(): void
    {
        $dir = \dirname(__DIR__, 3).'/translations';
        foreach (['fr', 'en'] as $locale) {
            $messages = Yaml::parseFile($dir.'/messages.'.$locale.'.yaml');
            foreach (['no_target', 'sport_required', 'sport_invalid', 'duplicate_sport', 'distance_range', 'sessions_range', 'duration_range', 'duration_invalid', 'elevation_range'] as $key) {
                self::assertIsString($messages['settings']['goals']['error'][$key] ?? null, "$locale: settings.goals.error.$key");
            }
        }

        $fr = Yaml::parseFile($dir.'/messages.fr.yaml');
        $en = Yaml::parseFile($dir.'/messages.en.yaml');
        self::assertSame(self::keys($fr['settings']['goals']), self::keys($en['settings']['goals']));
        self::assertSame(self::keys($fr['objectives']), self::keys($en['objectives']));
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function keys(array $tree, string $prefix = ''): array
    {
        $keys = [];
        foreach ($tree as $key => $value) {
            $keys = \is_array($value) ? [...$keys, ...self::keys($value, $prefix.$key.'.')] : [...$keys, $prefix.$key];
        }
        sort($keys);

        return $keys;
    }
}
