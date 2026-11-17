<?php

namespace App\Tests\Unit\Controller\Settings;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Suppression du compte : section « account » présente en français ET en anglais, mêmes clés et mêmes
 * paramètres, et chaque clé utilisée par le gabarit et le contrôleur existe dans les deux langues.
 */
final class AccountTranslationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    /** @return array{fr: array<string, mixed>, en: array<string, mixed>} */
    private static function messages(): array
    {
        return [
            'fr' => Yaml::parseFile(self::ROOT.'/translations/messages.fr.yaml'),
            'en' => Yaml::parseFile(self::ROOT.'/translations/messages.en.yaml'),
        ];
    }

    public function testAccountSectionHasTheSameKeysInBothLanguages(): void
    {
        ['fr' => $fr, 'en' => $en] = self::messages();
        $this->assertIsArray($fr['account'] ?? null, 'account missing in fr');
        $this->assertIsArray($en['account'] ?? null, 'account missing in en');

        $frKeys = array_keys($fr['account']);
        $enKeys = array_keys($en['account']);
        sort($frKeys);
        sort($enKeys);
        $this->assertSame($frKeys, $enKeys);

        foreach ($fr['account'] as $key => $value) {
            $this->assertIsString($value, "fr account.$key");
            $this->assertIsString($en['account'][$key], "en account.$key");
            $this->assertNotSame('', trim($value), "fr account.$key");
            $this->assertNotSame('', trim($en['account'][$key]), "en account.$key");
            preg_match_all('/%\w+%/', $value, $a);
            preg_match_all('/%\w+%/', $en['account'][$key], $b);
            sort($a[0]);
            sort($b[0]);
            $this->assertSame($a[0], $b[0], "account.$key placeholders");
        }
    }

    public function testEveryKeyUsedByTheFeatureExists(): void
    {
        $used = [];
        foreach (['templates/settings/_account.html.twig', 'src/Controller/Settings/AccountController.php'] as $file) {
            preg_match_all("/'(account\\.[a-z0-9_]+)'/", (string) file_get_contents(self::ROOT.'/'.$file), $m);
            $used = array_merge($used, $m[1]);
        }
        $used = array_values(array_unique($used));
        // Toutes les clés de la section servent (pas de clé orpheline)
        $sectionKeys = array_map(static fn (string $key): string => 'account.'.$key, array_keys(self::messages()['fr']['account']));
        sort($sectionKeys);
        $sortedUsed = $used;
        sort($sortedUsed);
        $this->assertSame($sectionKeys, $sortedUsed);

        foreach (self::messages() as $locale => $messages) {
            foreach (array_merge($used, ['settings.nav.account']) as $key) {
                [$section, $name] = explode('.', $key, 2);
                $value = $messages[$section] ?? null;
                foreach (explode('.', $name) as $part) {
                    $value = \is_array($value) ? ($value[$part] ?? null) : null;
                }
                $this->assertIsString($value, "$key missing in messages.$locale.yaml");
            }
        }
    }

    public function testDeletedFlashWording(): void
    {
        $this->assertSame('Ton compte a été supprimé.', self::messages()['fr']['account']['flash_deleted']);
        $this->assertSame("Je comprends que c'est définitif", self::messages()['fr']['account']['delete_confirm_label']);
    }
}
