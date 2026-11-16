<?php

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\PeriodComparison;
use App\Service\Stats\PeriodComparisonResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * Traductions de la vue « Comparer » : mêmes clés et mêmes paramètres en fr et en en, et toutes les clés
 * utilisées par les templates, le contrôleur et les services existent dans les deux langues.
 */
final class StatsCompareTranslationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $out += self::flatten($value, $prefix.$key.'.');
            } else {
                $out[$prefix.$key] = (string) $value;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function messages(string $locale): array
    {
        $all = Yaml::parseFile(self::ROOT.'/translations/messages.'.$locale.'.yaml');
        self::assertIsArray($all);

        return self::flatten($all);
    }

    public function testCompareSectionHasTheSameKeysAndPlaceholdersInBothLanguages(): void
    {
        $fr = array_filter(self::messages('fr'), static fn (string $k): bool => str_starts_with($k, 'stats.compare.') || str_starts_with($k, 'stats.view_'), ARRAY_FILTER_USE_KEY);
        $en = array_filter(self::messages('en'), static fn (string $k): bool => str_starts_with($k, 'stats.compare.') || str_starts_with($k, 'stats.view_'), ARRAY_FILTER_USE_KEY);
        self::assertGreaterThan(100, count($fr));

        $frKeys = array_keys($fr);
        $enKeys = array_keys($en);
        sort($frKeys);
        sort($enKeys);
        self::assertSame($frKeys, $enKeys);

        foreach ($fr as $key => $value) {
            self::assertNotSame('', trim($en[$key]), "en $key");
            $params = static function (string $text): array {
                preg_match_all('/%\w+%/', $text, $m);
                $found = array_values(array_unique($m[0]));
                sort($found);

                return $found;
            };
            self::assertSame($params($value), $params($en[$key]), "$key placeholders");
            self::assertSame(substr_count($value, '|'), substr_count($en[$key], '|'), "$key plural forms");
        }
    }

    public function testEveryKeyUsedExistsInBothLanguages(): void
    {
        $sources = '';
        foreach (['templates/stats/compare.html.twig', 'templates/stats/_compare_kpi.html.twig', 'templates/stats/_compare_delta.html.twig',
            'templates/stats/_overview_delta.html.twig', 'templates/stats/_view_tabs.html.twig', 'templates/stats/index.html.twig',
            'src/Controller/Stats/StatsCompareController.php', 'src/Service/Stats/PeriodComparisonResolver.php'] as $file) {
            $sources .= (string) file_get_contents(self::ROOT.'/'.$file);
        }
        // Clés littérales ; celles suivies d'une concaténation (Twig ~, PHP .) sont des préfixes, listés plus bas
        preg_match_all("/'(stats\\.(?:compare|view)[a-z0-9_.]*)'(?!\\s*[~.])/", $sources, $m);
        $used = array_values(array_unique($m[1]));
        self::assertContains('stats.compare.error_span', $used);
        self::assertContains('stats.view_compare', $used);
        foreach (array_merge(PeriodComparisonResolver::PRESETS, ['custom']) as $preset) {
            $used[] = 'stats.compare.preset.'.$preset;
            if ($preset !== 'custom') {
                $used[] = 'stats.compare.rule.'.$preset;
            }
        }
        foreach ([PeriodComparison::NOTE_FULL_MONTH, PeriodComparison::NOTE_CLAMPED, PeriodComparison::NOTE_LEAP_DAY] as $note) {
            $used[] = 'stats.compare.note.'.$note;
        }
        foreach (['distance', 'time', 'elevation', 'sessions', 'pace', 'speed', 'heartrate', 'longest', 'biggest_climb', 'distance_week', 'time_week', 'sessions_week'] as $metric) {
            $used[] = 'stats.compare.metric.'.$metric;
        }
        foreach (['distance', 'time', 'elevation', 'sessions'] as $metric) {
            $used[] = 'stats.compare.chart_metric.'.$metric;
        }
        foreach (['up', 'down', 'flat', 'none'] as $direction) {
            $used[] = 'stats.compare.sr.'.$direction;
        }
        foreach (['day', 'week', 'month'] as $granularity) {
            foreach (['buckets_title_', 'buckets_sub_', 'aria_buckets_', 'col_'] as $prefix) {
                $used[] = 'stats.compare.'.$prefix.$granularity;
            }
        }
        foreach (['7d', 'month', 'season'] as $period) {
            $used[] = 'stats.compare.overview_caption_'.$period;
        }
        foreach (range(1, 12) as $month) {
            $used[] = 'stats.compare.month_short.m'.$month;
        }
        $used[] = 'stats.compare.pace_up';
        $used[] = 'stats.compare.pace_down';
        self::assertGreaterThan(90, count(array_unique($used)));

        foreach (['fr', 'en'] as $locale) {
            $messages = self::messages($locale);
            foreach (array_unique($used) as $key) {
                self::assertArrayHasKey($key, $messages, "$key missing in messages.$locale.yaml");
            }
        }
    }

    public function testPluralRules(): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', self::ROOT.'/translations/messages.fr.yaml', 'fr');
        $translator->addResource('yaml', self::ROOT.'/translations/messages.en.yaml', 'en');

        self::assertSame('1 jour', $translator->trans('stats.compare.days', ['%count%' => 1]));
        self::assertSame('16 jours', $translator->trans('stats.compare.days', ['%count%' => 16]));
        self::assertSame('1 day', $translator->trans('stats.compare.days', ['%count%' => 1], null, 'en'));
        self::assertSame('366 days', $translator->trans('stats.compare.days', ['%count%' => 366], null, 'en'));
        self::assertSame(
            'À date : du 1er au 16 du mois, comparé aux mêmes jours du mois dernier.',
            $translator->trans('stats.compare.rule.month', ['%count%' => 16])
        );
        self::assertSame(
            'À date : le lundi, comparé au lundi de la semaine dernière.',
            $translator->trans('stats.compare.rule.week', ['%count%' => 1])
        );
        self::assertSame('Année 2025 vs 2024', $translator->trans('stats.compare.preset.last_year', ['%last%' => 2025, '%before%' => 2024]));
    }
}
