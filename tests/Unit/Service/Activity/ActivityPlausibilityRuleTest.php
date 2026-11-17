<?php

namespace App\Tests\Unit\Service\Activity;

use App\Service\Activity\ActivityPlausibilityRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Allure ou vitesse invraisemblable (avertissement confirmable) : sports à allure plus lents que 30:00 /km ou plus
 * rapides que 2:00 /km, vélo au-dessus de 80 km/h ; bornes incluses dans le vraisemblable ; miroir JS identique.
 */
final class ActivityPlausibilityRuleTest extends TestCase
{
    /** @return iterable<string, array{string, int, ?float, ?string, array<string, int|string>}> */
    public static function cases(): iterable
    {
        yield 'course 5:00 /km' => ['run', 3000, 10000.0, null, []];
        yield 'course 30:00 /km tout juste' => ['run', 1800, 1000.0, null, []];
        yield 'course 30:01 /km' => ['run', 1801, 1000.0, 'activity_form.plausibility.pace_slow', ['%value%' => '30:01', '%limit%' => '30:00']];
        yield '45 min pour 1 km' => ['run', 2700, 1000.0, 'activity_form.plausibility.pace_slow', ['%value%' => '45:00', '%limit%' => '30:00']];
        yield '45 h 30 pour 10,5 km (saisie ambiguë)' => ['run', 163800, 10500.0, 'activity_form.plausibility.pace_slow', ['%value%' => '260:00', '%limit%' => '30:00']];
        yield 'course 2:00 /km tout juste' => ['run', 240, 2000.0, null, []];
        yield 'course 1:59 /km' => ['run', 119, 1000.0, 'activity_form.plausibility.pace_fast', ['%value%' => '1:59', '%limit%' => '2:00']];
        yield 'trail lent' => ['trail', 2400, 1000.0, 'activity_form.plausibility.pace_slow', ['%value%' => '40:00', '%limit%' => '30:00']];
        yield 'marche 12:00 /km' => ['walk', 3600, 5000.0, null, []];
        yield 'randonnée rapide' => ['hike', 600, 10000.0, 'activity_form.plausibility.pace_fast', ['%value%' => '1:00', '%limit%' => '2:00']];
        yield 'vélo 80 km/h tout juste' => ['ride', 3600, 80000.0, null, []];
        yield 'vélo 100 km/h' => ['ride', 3600, 100000.0, 'activity_form.plausibility.speed_high', ['%value%' => 100, '%limit%' => 80]];
        yield 'vélo très lent : pas vérifié' => ['ride', 36000, 1000.0, null, []];
        yield 'natation non vérifiée' => ['swim', 3600, 1000.0, null, []];
        yield 'autre non vérifié' => ['other', 60, 10000.0, null, []];
        yield 'sans distance' => ['run', 3600, null, null, []];
        yield 'distance nulle' => ['run', 3600, 0.0, null, []];
    }

    /** @param array<string, int|string> $params */
    #[Test]
    #[DataProvider('cases')]
    public function check(string $sport, int $moving, ?float $meters, ?string $key, array $params): void
    {
        $warning = ActivityPlausibilityRule::check($sport, $moving, $meters);
        self::assertSame($key, $warning?->key);
        if ($warning !== null) {
            self::assertSame($params, $warning->params);
        }
    }

    #[Test]
    public function jsMirrorUsesTheSameThresholds(): void
    {
        self::assertSame(['paceSports' => ['run', 'trail', 'walk', 'hike'], 'slowestPace' => 1800, 'fastestPace' => 120, 'rideMaxKmh' => 80], ActivityPlausibilityRule::forJs());
        $js = (string) file_get_contents(__DIR__.'/../../../../assets/controllers/activity_form_controller.js');
        // Le JS lit les seuils dans la valeur Stimulus plausibility (aucun seuil recopié en dur)
        self::assertStringContainsString('rules.slowestPace', $js);
        self::assertStringContainsString('rules.fastestPace', $js);
        self::assertStringContainsString('rules.rideMaxKmh', $js);
        self::assertStringNotContainsString('1800', $js);
    }
}
