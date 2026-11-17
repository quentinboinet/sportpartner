<?php

namespace App\Tests\Unit\Service\Activity;

use App\Service\Activity\ActivityCsvExporter;
use App\Service\Activity\ActivitySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Saisie manuelle et export CSV : sections activity_form, activity_source et activity_export (plus les boutons
 * Exporter) présentes en français ET en anglais avec les mêmes paramètres, sans tiret interdit, et chaque clé utilisée
 * par les gabarits, contrôleurs et services de la fonctionnalité existe dans les deux langues.
 */
final class ManualActivityTranslationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';
    private const PREFIXES = ['activity_form.', 'activity_source.', 'activity_export.'];
    private const EXTRA = ['activities.btn_export', 'activities.export_title', 'activities.btn_add', 'stats.export', 'stats.export_title', 'dashboard.add_activity'];

    /** @return array<string, string> */
    private static function flat(string $locale): array
    {
        $out = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, $prefix.$key.'.') : $out[$prefix.$key] = (string) $value;
            }
        };
        $walk(Yaml::parseFile(self::ROOT."/translations/messages.$locale.yaml"), '');

        return $out;
    }

    /** @return array<string, string> */
    private static function feature(string $locale): array
    {
        return array_filter(self::flat($locale), static function (string $key): bool {
            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }

            return in_array($key, self::EXTRA, true);
        }, ARRAY_FILTER_USE_KEY);
    }

    #[Test]
    public function sameKeysAndPlaceholdersInBothLanguages(): void
    {
        $fr = self::feature('fr');
        $en = self::feature('en');
        self::assertGreaterThan(90, count($fr));
        self::assertSame(array_keys($fr), array_keys($en));
        foreach ($fr as $key => $text) {
            self::assertNotSame('', trim($text), "fr $key");
            self::assertNotSame('', trim($en[$key]), "en $key");
            preg_match_all('/%\w+%/', $text, $a);
            preg_match_all('/%\w+%/', $en[$key], $b);
            $a = array_values(array_unique($a[0]));
            $b = array_values(array_unique($b[0]));
            sort($a);
            sort($b);
            self::assertSame($a, $b, "$key placeholders");
            foreach (["\u{2014}", "\u{2013}", "\u{2212}"] as $dash) {
                self::assertStringNotContainsString($dash, $text.$en[$key], $key);
            }
        }
        // Le préfixe du nom de fichier reste en ASCII (en-tête Content-Disposition)
        self::assertMatchesRegularExpression('/^[a-z-]+$/', $fr['activity_export.filename']);
        self::assertMatchesRegularExpression('/^[a-z-]+$/', $en['activity_export.filename']);
    }

    #[Test]
    public function everyKeyUsedByTheFeatureExists(): void
    {
        $fr = self::flat('fr');
        $en = self::flat('en');
        $files = [
            'templates/activities/form.html.twig', 'templates/activities/show.html.twig', 'templates/activities/index.html.twig',
            'templates/stats/index.html.twig', 'templates/dashboard/index.html.twig',
            'src/Controller/Activities/ManualActivityController.php', 'src/Controller/Activities/ActivitiesController.php',
            'src/Controller/Stats/StatsExportController.php', 'src/Service/Activity/ManualActivityForm.php',
            'src/Service/Activity/ActivityCsvExporter.php', 'src/Service/Activity/ActivityPlausibilityRule.php',
        ];
        $used = [];
        foreach ($files as $file) {
            preg_match_all("/'((?:activity_form|activity_source|activity_export|activities|stats|dashboard|gear)\.[a-z0-9_.]+[a-z0-9_])'/", (string) file_get_contents(self::ROOT.'/'.$file), $m);
            foreach ($m[1] as $key) {
                $used[$key] = $file;
            }
        }
        foreach (ActivitySource::cases() as $source) {
            $used[$source->labelKey()] = 'ActivitySource';
        }
        foreach (ActivityCsvExporter::COLUMNS as $column) {
            $used['activity_export.col_'.$column] = 'ActivityCsvExporter';
        }

        self::assertGreaterThan(60, count($used));
        foreach ($used as $key => $file) {
            if (str_ends_with($key, '_')) {
                continue; // préfixe d'une clé construite (« 'gear.group_' ~ t »)
            }
            self::assertArrayHasKey($key, $fr, "fr $key ($file)");
            self::assertArrayHasKey($key, $en, "en $key ($file)");
        }
    }
}
