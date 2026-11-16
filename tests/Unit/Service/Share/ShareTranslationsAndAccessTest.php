<?php

namespace App\Tests\Unit\Service\Share;

use App\Controller\Race\RacePublicController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Partage public : clés de traduction présentes en français ET en anglais (y compris toutes celles utilisées par les
 * gabarits et contrôleurs), accès public limité à ^/p/ (avant la règle ^/), choix de la langue de la page publique.
 */
final class ShareTranslationsAndAccessTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';
    private const SECTIONS = ['race_share', 'race_public'];

    #[Test]
    public function sectionsHaveTheSameKeysInBothLanguages(): void
    {
        $fr = Yaml::parseFile(self::ROOT.'/translations/messages.fr.yaml');
        $en = Yaml::parseFile(self::ROOT.'/translations/messages.en.yaml');

        foreach (self::SECTIONS as $section) {
            self::assertIsArray($fr[$section] ?? null, "$section missing in fr");
            self::assertIsArray($en[$section] ?? null, "$section missing in en");
            $frKeys = array_keys($fr[$section]);
            $enKeys = array_keys($en[$section]);
            sort($frKeys);
            sort($enKeys);
            self::assertSame($frKeys, $enKeys, $section);
            foreach ([$fr, $en] as $messages) {
                foreach ($messages[$section] as $key => $value) {
                    self::assertIsString($value, "$section.$key");
                    self::assertNotSame('', trim($value), "$section.$key");
                }
            }
            // Mêmes paramètres (%name%, %time%...) dans les deux langues
            foreach ($fr[$section] as $key => $value) {
                preg_match_all('/%\w+%/', $value, $a);
                preg_match_all('/%\w+%/', $en[$section][$key], $b);
                sort($a[0]);
                sort($b[0]);
                self::assertSame($a[0], $b[0], "$section.$key placeholders");
            }
        }
    }

    #[Test]
    public function everyKeyUsedByTheFeatureExists(): void
    {
        $files = [
            'templates/race/share.html.twig',
            'templates/race/public.html.twig',
            'templates/race/show.html.twig',
            'templates/race/plan.html.twig',
            'src/Controller/Race/RaceShareController.php',
            'src/Controller/Race/RacePublicController.php',
        ];
        $used = [];
        foreach ($files as $file) {
            preg_match_all("/'((?:race_share|race_public)\\.[a-z0-9_]+)'/", (string) file_get_contents(self::ROOT.'/'.$file), $m);
            $used = array_merge($used, $m[1]);
        }
        // Clés construites dynamiquement ('race_share.error_' ~ code) : le préfixe est remplacé par les clés possibles
        $used = array_filter($used, static fn (string $key): bool => !str_ends_with($key, '_'));
        $used = array_merge($used, ['race_share.error_too_long', 'race_share.error_invalid']);
        $used = array_values(array_unique($used));
        self::assertGreaterThan(60, \count($used));

        foreach (['fr', 'en'] as $locale) {
            $messages = Yaml::parseFile(self::ROOT.'/translations/messages.'.$locale.'.yaml');
            foreach ($used as $key) {
                [$section, $name] = explode('.', $key, 2);
                self::assertIsString($messages[$section][$name] ?? null, "$key missing in messages.$locale.yaml");
            }
        }
    }

    #[Test]
    public function onlySharedPlanPathsArePublic(): void
    {
        $rules = Yaml::parseFile(self::ROOT.'/config/packages/security.yaml')['security']['access_control'];
        $paths = array_column($rules, 'path');
        $public = array_search('^/p/', $paths, true);
        $all = array_search('^/', $paths, true);

        self::assertIsInt($public);
        self::assertIsInt($all);
        self::assertLessThan($all, $public, '^/p/ must come before ^/');
        self::assertSame('PUBLIC_ACCESS', $rules[$public]['roles']);
        self::assertSame('ROLE_USER', $rules[$all]['roles']);

        foreach (['/p/abc', '/p/abc/calendar.ics'] as $path) {
            self::assertSame(1, preg_match('#^/p/#', $path), $path);
        }
        foreach (['/profile', '/plan', '/p', '/courses/1/partage', '/courses/1/plan', '/pXYZ/'] as $path) {
            self::assertSame(0, preg_match('#^/p/#', $path), $path);
        }
    }

    #[Test]
    public function publicPageLanguage(): void
    {
        $request = static function (string $query, ?string $acceptLanguage): Request {
            $request = Request::create('/p/token'.$query);
            if (null !== $acceptLanguage) {
                $request->headers->set('Accept-Language', $acceptLanguage);
            } else {
                $request->headers->remove('Accept-Language');
            }

            return $request;
        };

        self::assertSame('en', RacePublicController::locale($request('?lang=en', 'fr-FR,fr;q=0.9')));
        self::assertSame('fr', RacePublicController::locale($request('?lang=fr', 'en-US,en;q=0.9')));
        self::assertSame('en', RacePublicController::locale($request('', 'en-GB,en;q=0.9,fr;q=0.5')));
        self::assertSame('fr', RacePublicController::locale($request('', 'fr-CA,fr;q=0.9,en;q=0.5')));
        self::assertSame('fr', RacePublicController::locale($request('', 'de-DE,de;q=0.9')), 'no match: French');
        self::assertSame('fr', RacePublicController::locale($request('', null)), 'no header: French');
        self::assertSame('fr', RacePublicController::locale($request('?lang=de', null)), 'unknown lang ignored');
        self::assertSame('en', RacePublicController::locale($request('?lang[]=en', 'en')), 'array lang ignored');
    }
}
