<?php

namespace App\Tests\Unit\Service\Health;

use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthEquipment;
use App\Entity\HealthLevel;
use App\Repository\HealthSessionRepository;
use App\Twig\HealthExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * Espace Santé : clés de traduction présentes en français ET en anglais (section health, entrée de menu, cloche),
 * mêmes paramètres, toutes les clés utilisées par les gabarits, contrôleurs, services et énumérations existent,
 * aucun tiret interdit ; mise en forme des durées et dosages (miroir de seconds() / duration() du lecteur JS).
 */
final class HealthTranslationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    /** @return array<string, string> clés à plat (« health.form.error.range ») => texte */
    private static function flat(string $locale): array
    {
        $out = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
            foreach ($node as $key => $value) {
                $path = $prefix.$key;
                is_array($value) ? $walk($value, $path.'.') : $out[$path] = (string) $value;
            }
        };
        $walk(Yaml::parseFile(self::ROOT."/translations/messages.$locale.yaml"), '');

        return $out;
    }

    /** @return array<string, string> */
    private static function health(string $locale): array
    {
        return array_filter(self::flat($locale), static fn (string $k): bool => str_starts_with($k, 'health.') || $k === 'nav.health' || str_starts_with($k, 'notifications.health_'), ARRAY_FILTER_USE_KEY);
    }

    #[Test]
    public function sameKeysAndPlaceholdersInBothLanguages(): void
    {
        $fr = self::health('fr');
        $en = self::health('en');
        self::assertGreaterThan(250, count($fr));
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
    }

    #[Test]
    public function everyKeyUsedByTheFeatureExists(): void
    {
        $fr = self::flat('fr');
        $en = self::flat('en');
        $files = array_merge(
            glob(self::ROOT.'/templates/health/*.twig') ?: [],
            glob(self::ROOT.'/src/Controller/Health/*.php') ?: [],
            glob(self::ROOT.'/src/Service/Health/*.php') ?: [],
            [self::ROOT.'/templates/activities/show.html.twig', self::ROOT.'/templates/base.html.twig', self::ROOT.'/src/Service/Notifications/TodoBuilder.php', self::ROOT.'/src/Twig/HealthExtension.php'],
        );
        $used = [];
        foreach ($files as $file) {
            preg_match_all("/'((?:health|nav|notifications|sport)\.[a-z0-9_.]+[a-z0-9_])'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                $used[$key] = basename($file);
            }
        }
        // Clés construites dynamiquement
        foreach ([HealthCategory::cases(), HealthLevel::cases(), HealthBodyArea::cases(), HealthEquipment::cases()] as $cases) {
            foreach ($cases as $case) {
                $used[$case->labelKey()] = 'enum';
            }
        }
        foreach (HealthDosageMode::cases() as $mode) {
            $used['health.form.mode_'.$mode->value] = 'exercise_form';
        }
        foreach (['strength', 'mobility', 'mixed'] as $kind) {
            $used['health.session.default_name.'.$kind] = 'HealthSessionController';
        }
        foreach (range(1, 12) as $month) {
            $used['health.month.'.$month] = 'history';
        }
        $used['health.equipment.none'] = 'filter';

        self::assertGreaterThan(200, count($used));
        foreach ($used as $key => $file) {
            if (str_ends_with($key, '.') || str_ends_with($key, '_')) {
                continue; // préfixe d'une clé construite (« 'health.form.mode_' ~ m.value »), vérifiée plus haut
            }
            self::assertArrayHasKey($key, $fr, "fr $key ($file)");
            self::assertArrayHasKey($key, $en, "en $key ($file)");
        }
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', self::ROOT.'/translations/messages.fr.yaml', 'fr');
        $translator->addResource('yaml', self::ROOT.'/translations/messages.en.yaml', 'en');

        return $translator;
    }

    #[Test]
    public function bellTextIsPluralizedInBothLanguages(): void
    {
        $fr = self::translator('fr');
        $en = self::translator('en');
        self::assertSame('0 / 2 séances de renfo cette semaine', $fr->trans('notifications.health_strength_none', ['%count%' => '2']));
        self::assertSame('0 / 1 séance de renfo cette semaine', $fr->trans('notifications.health_strength_none', ['%count%' => '1']));
        self::assertSame('0 / 3 strength sessions this week', $en->trans('notifications.health_strength_none', ['%count%' => '3'], 'messages', 'en'));
        self::assertSame('0 / 1 strength session this week', $en->trans('notifications.health_strength_none', ['%count%' => '1'], 'messages', 'en'));
    }

    #[Test]
    public function durationsAndDosesAreFormattedLikeThePlayer(): void
    {
        $ext = new HealthExtension($this->createStub(HealthSessionRepository::class), self::translator('fr'));
        self::assertSame(['45 s', '1 min', '1 min 30 s', '0 s'], [$ext->seconds(45), $ext->seconds(60), $ext->seconds(90), $ext->seconds(-3)]);
        self::assertSame(['40 s', '1 min', '25 min', '1 h 05', '2 h 00'], [$ext->duration(40), $ext->duration(89), $ext->duration(1500), $ext->duration(3900), $ext->duration(7199)]);
        self::assertSame('3 × 12 rép.', $ext->dose(3, 12, HealthDosageMode::Reps));
        self::assertSame('2 × 1 min 30 s chaque côté', $ext->dose(2, 90, 'time', true));

        $en = new HealthExtension($this->createStub(HealthSessionRepository::class), self::translator('en'));
        self::assertSame('3 × 12 reps each side', $en->dose(3, 12, 'reps', true));
    }
}
