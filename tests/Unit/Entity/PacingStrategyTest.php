<?php

namespace App\Tests\Unit\Entity;

use App\Entity\PacingIntensity;
use App\Entity\PacingStrategy;
use App\Entity\RaceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PacingStrategyTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * m(x) attendu à x = 0, 0.3, 0.5 et 1 pour chaque stratégie et intensité (valeurs calculées à la main à partir
     * des formules documentées dans PacingStrategy).
     *
     * @return iterable<string, array{PacingStrategy, PacingIntensity, array{float, float, float, float}}>
     */
    public static function multipliers(): iterable
    {
        $expected = [
            'even' => ['light' => [1, 1, 1, 1], 'medium' => [1, 1, 1, 1], 'strong' => [1, 1, 1, 1]],
            // a = 0.02 / 0.04 / 0.06 : 1 + a au départ, 1 à partir de 30 %
            'conservative' => ['light' => [1.02, 1, 1, 1], 'medium' => [1.04, 1, 1, 1], 'strong' => [1.06, 1, 1, 1]],
            // k = 0.03 / 0.06 / 0.10 : 1 + k/2 au départ, 1 à mi-course, 1 - k/2 à l'arrivée
            'negative' => ['light' => [1.015, 1.006, 1, 0.985], 'medium' => [1.03, 1.012, 1, 0.97], 'strong' => [1.05, 1.02, 1, 0.95]],
            // k = 0.02 / 0.04 / 0.08 : 1 - k/2 au départ, 1 + k/2 à l'arrivée
            'aggressive' => ['light' => [0.99, 0.996, 1, 1.01], 'medium' => [0.98, 0.992, 1, 1.02], 'strong' => [0.96, 0.984, 1, 1.04]],
            // f = 0.08 / 0.15 / 0.25 : 1 - f/2 au départ, 1 + f/2 à l'arrivée
            'ultra' => ['light' => [0.96, 0.984, 1, 1.04], 'medium' => [0.925, 0.97, 1, 1.075], 'strong' => [0.875, 0.95, 1, 1.125]],
        ];
        foreach ($expected as $strategy => $byIntensity) {
            foreach ($byIntensity as $intensity => $values) {
                yield $strategy.'/'.$intensity => [PacingStrategy::from($strategy), PacingIntensity::from($intensity), array_map('floatval', $values)];
            }
        }
    }

    /** @param array{float, float, float, float} $expected */
    #[Test]
    #[DataProvider('multipliers')]
    public function multiplierAtKeyPositions(PacingStrategy $strategy, PacingIntensity $intensity, array $expected): void
    {
        foreach ([0.0, 0.3, 0.5, 1.0] as $i => $x) {
            self::assertEqualsWithDelta($expected[$i], $strategy->multiplier($x, $intensity), 1e-12, sprintf('%s %s x=%s', $strategy->value, $intensity->value, $x));
        }
    }

    #[Test]
    public function everyMultiplierIsPositiveAndContinuous(): void
    {
        foreach (PacingStrategy::cases() as $strategy) {
            foreach (PacingIntensity::cases() as $intensity) {
                $previous = $strategy->multiplier(0.0, $intensity);
                for ($i = 1; $i <= 1000; ++$i) {
                    $m = $strategy->multiplier($i / 1000, $intensity);
                    self::assertGreaterThan(0.8, $m);
                    self::assertLessThan(1.2, $m);
                    // Pas de saut (Départ prudent rejoint 1 à 30 % sans marche)
                    self::assertLessThan(0.001, abs($m - $previous), sprintf('%s %s x=%s', $strategy->value, $intensity->value, $i / 1000));
                    $previous = $m;
                }
            }
        }
    }

    #[Test]
    public function mediumIntensityMatchesTheAnnouncedEffects(): void
    {
        $medium = PacingIntensity::Medium;
        $average = static function (PacingStrategy $s, float $from, float $to) use ($medium): float {
            $sum = 0.0;
            for ($i = 0; $i < 1000; ++$i) {
                $sum += $s->multiplier($from + ($to - $from) * ($i + 0.5) / 1000, $medium);
            }

            return $sum / 1000;
        };

        // Negative split : première moitié environ 1,5 % plus lente, seconde environ 1,5 % plus rapide
        self::assertEqualsWithDelta(1.015, $average(PacingStrategy::Negative, 0.0, 0.5), 1e-9);
        self::assertEqualsWithDelta(0.985, $average(PacingStrategy::Negative, 0.5, 1.0), 1e-9);
        // Prise de risque : départ environ 2 % plus rapide, plus lent ensuite
        self::assertEqualsWithDelta(0.98, PacingStrategy::Aggressive->multiplier(0.0, $medium), 1e-12);
        self::assertGreaterThan(1.0, $average(PacingStrategy::Aggressive, 0.5, 1.0));
        // Gestion ultra : fin environ 15 % plus lente que le début
        $ratio = PacingStrategy::Ultra->multiplier(1.0, $medium) / PacingStrategy::Ultra->multiplier(0.0, $medium);
        self::assertGreaterThan(1.14, $ratio);
        self::assertLessThan(1.17, $ratio);
        // Départ prudent : seulement les 30 premiers %
        self::assertGreaterThan(1.0, PacingStrategy::Conservative->multiplier(0.29, $medium));
        self::assertSame(1.0, PacingStrategy::Conservative->multiplier(0.3, $medium));
    }

    #[Test]
    public function evenIgnoresIntensity(): void
    {
        self::assertFalse(PacingStrategy::Even->usesIntensity());
        foreach (PacingIntensity::cases() as $intensity) {
            self::assertSame(0.0, PacingStrategy::Even->coefficient($intensity));
            self::assertSame(1.0, PacingStrategy::Even->multiplier(0.123, $intensity));
        }
        foreach ([PacingStrategy::Conservative, PacingStrategy::Negative, PacingStrategy::Aggressive, PacingStrategy::Ultra] as $strategy) {
            self::assertTrue($strategy->usesIntensity());
            self::assertGreaterThan(0.0, $strategy->coefficient(PacingIntensity::Light));
            self::assertGreaterThan($strategy->coefficient(PacingIntensity::Light), $strategy->coefficient(PacingIntensity::Medium));
            self::assertGreaterThan($strategy->coefficient(PacingIntensity::Medium), $strategy->coefficient(PacingIntensity::Strong));
        }
    }

    #[Test]
    public function suggestedStrategyDependsOnTheRaceType(): void
    {
        self::assertSame(PacingStrategy::Negative, PacingStrategy::suggestedFor(RaceType::Road));
        self::assertSame(PacingStrategy::Conservative, PacingStrategy::suggestedFor(RaceType::Trail));
        self::assertSame(PacingStrategy::Even, PacingStrategy::suggestedFor(null));
    }

    /** Validation des champs postés strategy / strategy_intensity (RaceScenarioController::parseFields). */
    #[Test]
    public function postedValuesAreValidatedExactly(): void
    {
        foreach (PacingStrategy::cases() as $strategy) {
            self::assertSame($strategy, PacingStrategy::tryFromInput($strategy->value));
        }
        foreach (PacingIntensity::cases() as $intensity) {
            self::assertSame($intensity, PacingIntensity::tryFromInput($intensity->value));
        }
        foreach (['', ' even', 'even ', 'Even', 'EVEN', 'negative_split', 'medium', '1', '0'] as $bad) {
            self::assertNull(PacingStrategy::tryFromInput($bad), $bad);
        }
        foreach (['', 'Medium', ' medium', 'moyenne', 'even', 'strong '] as $bad) {
            self::assertNull(PacingIntensity::tryFromInput($bad), $bad);
        }
        foreach ([null, 0, 1, 1.5, true, ['even'], ['strategy' => 'even']] as $bad) {
            self::assertNull(PacingStrategy::tryFromInput($bad));
            self::assertNull(PacingIntensity::tryFromInput($bad));
        }
    }

    #[Test]
    public function labelsAndDescriptionsAreTranslatedInBothLanguages(): void
    {
        $keys = [];
        foreach (PacingStrategy::cases() as $strategy) {
            $keys[] = $strategy->labelKey();
            $keys[] = $strategy->descriptionKey();
        }
        foreach (PacingIntensity::cases() as $intensity) {
            $keys[] = $intensity->labelKey();
        }
        $keys = array_merge($keys, [
            'race_plan.sc_field_strategy', 'race_plan.sc_field_intensity', 'race_plan.sc_strategy_suggested',
            'race_plan.sc_dev', 'race_plan.sc_dev_title', 'race_plan.sc_feature_strategy',
            'race_print.strategy', 'race_print.strategy_title', 'race_print.strategy_intensity', 'race_show.scenario_strategy_title',
        ]);

        foreach (['fr', 'en'] as $locale) {
            $messages = Yaml::parseFile(self::ROOT.'/translations/messages.'.$locale.'.yaml');
            foreach ($keys as $key) {
                [$domain, $name] = explode('.', $key, 2);
                self::assertIsString($messages[$domain][$name] ?? null, sprintf('%s missing in messages.%s.yaml', $key, $locale));
                self::assertNotSame('', trim($messages[$domain][$name]));
            }
        }
    }

    /**
     * Le calcul en direct (race_splits_controller.js) doit utiliser exactement les mêmes constantes : la CI n'exécute
     * pas de JS, ce test lit le fichier.
     */
    #[Test]
    public function javascriptMirrorUsesTheSameConstants(): void
    {
        $js = (string) file_get_contents(self::ROOT.'/assets/controllers/race_splits_controller.js');

        self::assertMatchesRegularExpression('/export const PACING_COEFFICIENTS = \{(.*?)\n\};/s', $js);
        preg_match('/export const PACING_COEFFICIENTS = \{(.*?)\n\};/s', $js, $block);
        preg_match_all('/(\w+): \{ light: ([\d.]+), medium: ([\d.]+), strong: ([\d.]+) \}/', $block[1], $rows, \PREG_SET_ORDER);
        $table = [];
        foreach ($rows as $row) {
            $table[$row[1]] = ['light' => (float) $row[2], 'medium' => (float) $row[3], 'strong' => (float) $row[4]];
        }
        self::assertSame(PacingStrategy::COEFFICIENTS, $table);

        self::assertMatchesRegularExpression('/export const PACING_CONSERVATIVE_UNTIL = ([\d.]+);/', $js);
        preg_match('/export const PACING_CONSERVATIVE_UNTIL = ([\d.]+);/', $js, $until);
        self::assertSame(PacingStrategy::CONSERVATIVE_UNTIL, (float) $until[1]);

        preg_match("/export const PACING_STRATEGIES = \\[(.*?)\\];/", $js, $strategies);
        self::assertSame(array_map(static fn (PacingStrategy $s): string => "'".$s->value."'", PacingStrategy::cases()), explode(', ', $strategies[1] ?? ''));
        preg_match("/export const PACING_INTENSITIES = \\[(.*?)\\];/", $js, $intensities);
        self::assertSame(array_map(static fn (PacingIntensity $i): string => "'".$i->value."'", PacingIntensity::cases()), explode(', ', $intensities[1] ?? ''));
    }
}
