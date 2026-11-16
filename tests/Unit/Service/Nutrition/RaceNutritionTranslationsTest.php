<?php

namespace App\Tests\Unit\Service\Nutrition;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Plan nutrition des courses : section race_nutrition présente en français ET en anglais (mêmes clés, mêmes
 * paramètres), et chaque clé utilisée par les gabarits, les contrôleurs et le JS existe.
 */
final class RaceNutritionTranslationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            if (\is_array($value)) {
                $out += self::flatten($value, $prefix.$key.'.');
            } else {
                $out[$prefix.$key] = (string) $value;
            }
        }

        return $out;
    }

    #[Test]
    public function sectionHasTheSameKeysAndPlaceholdersInBothLanguages(): void
    {
        $fr = self::flatten(Yaml::parseFile(self::ROOT.'/translations/messages.fr.yaml')['race_nutrition']);
        $en = self::flatten(Yaml::parseFile(self::ROOT.'/translations/messages.en.yaml')['race_nutrition']);

        self::assertSame(array_keys($fr), array_keys($en));
        foreach ($fr as $key => $value) {
            self::assertNotSame('', trim($value), "fr $key");
            self::assertNotSame('', trim($en[$key]), "en $key");
            preg_match_all('/%\w+%/', $value, $a);
            preg_match_all('/%\w+%/', $en[$key], $b);
            sort($a[0]);
            sort($b[0]);
            self::assertSame($a[0], $b[0], "$key placeholders");
        }
    }

    #[Test]
    public function everyKeyUsedByTheFeatureExists(): void
    {
        $files = [
            'templates/race/_nutrition.html.twig', 'templates/race/_nutrition_macros.html.twig', 'templates/race/plan.html.twig',
            'templates/race/plan_print.html.twig', 'templates/race/public.html.twig', 'templates/race/share.html.twig',
            'src/Controller/Race/RaceNutritionController.php', 'src/Controller/Race/RacePlanController.php',
        ];
        $used = [];
        foreach ($files as $file) {
            preg_match_all("/'(race_nutrition\\.[a-z0-9_.]+)'/", (string) file_get_contents(self::ROOT.'/'.$file), $m);
            $used = array_merge($used, $m[1]);
        }
        // Clés construites ('race_nutrition.units.' ~ key ~ '.one', 'race_nutrition.status_' ~ status...) : préfixe remplacé
        $used = array_filter($used, static fn (string $key): bool => !str_ends_with($key, '_') && !str_ends_with($key, '.'));
        foreach (['gel', 'puree', 'fruit_paste', 'gummies', 'bar'] as $food) {
            $used[] = "race_nutrition.units.$food.one";
            $used[] = "race_nutrition.units.$food.other";
        }
        foreach (['water', 'isotonic', 'energy_drink'] as $drink) {
            $used[] = "race_nutrition.drink_names.$drink";
        }
        foreach (['below', 'within', 'above'] as $status) {
            $used[] = "race_nutrition.status_$status";
        }
        foreach (['gel', 'solid', 'drink'] as $cat) {
            $used[] = "race_nutrition.cat_$cat";
            $used[] = "race_nutrition.auto_$cat";
        }
        $used[] = 'race_nutrition.add_group_food';
        $used[] = 'race_nutrition.add_group_drink';
        $used = array_values(array_unique($used));
        self::assertGreaterThan(80, \count($used));

        foreach (['fr', 'en'] as $locale) {
            $messages = self::flatten(Yaml::parseFile(self::ROOT.'/translations/messages.'.$locale.'.yaml'));
            foreach ($used as $key) {
                self::assertArrayHasKey($key, $messages, "$key missing in messages.$locale.yaml");
            }
        }
    }
}
