<?php

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Templates et entrées JS compatibles avec la CSP appliquée (ContentSecurityPolicy) : sans nonce, un script inline ne
 * s'exécute pas, et un gestionnaire inline (onsubmit="return confirm(...)") est ignoré : une suppression partirait
 * sans confirmation. Règles : nonce sur chaque <script> inline, importmap() avec le nonce, aucun attribut on*= ni lien
 * javascript:, aucun script ou feuille de style externe, aucun CSS importé depuis le JS (module data: refusé).
 */
final class CspTemplateComplianceTest extends TestCase
{
    private const NONCE_ATTRIBUTE = 'nonce="{{ csp_nonce() }}"';

    /**
     * @return array<string, string> chemin relatif => contenu
     */
    private static function templates(): array
    {
        $root  = \dirname(__DIR__, 3);
        $files = [];
        foreach ((new Finder())->files()->in($root.'/templates')->name('*.twig')->sortByName() as $file) {
            $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
        }
        self::assertNotEmpty($files);

        return $files;
    }

    public function testEveryInlineScriptCarriesTheNonce(): void
    {
        $missing = [];
        foreach (self::templates() as $path => $content) {
            preg_match_all('/<script\b[^>]*>/i', $content, $matches);
            foreach ($matches[0] as $tag) {
                if (preg_match('/type\s*=\s*["\']application\/(?:ld\+)?json["\']/i', $tag) === 1) {
                    continue; // bloc de données, jamais exécuté
                }
                if (!str_contains($tag, self::NONCE_ATTRIBUTE)) {
                    $missing[] = $path.': '.$tag;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testImportmapCallsPassTheNonce(): void
    {
        $calls = 0;
        foreach (self::templates() as $path => $content) {
            preg_match_all('/importmap\((.*?)\)\s*}}/', $content, $matches);
            foreach ($matches[1] as $arguments) {
                ++$calls;
                self::assertStringContainsString('nonce: csp_nonce()', $arguments, $path);
            }
        }

        self::assertSame(2, $calls, 'base.html.twig (app) et race/public.html.twig (race_public)');
    }

    public function testNoInlineEventHandlerNorJavascriptUrl(): void
    {
        $found = [];
        foreach (self::templates() as $path => $content) {
            if (preg_match_all('/<[a-z][^>]*\son[a-z]{3,}\s*=\s*["\']/i', $content, $m) > 0) {
                $found[] = $path.': '.implode(' | ', $m[0]);
            }
            if (preg_match('/(?:href|src|action)\s*=\s*["\']\s*javascript:/i', $content) === 1) {
                $found[] = $path.': javascript: URL';
            }
        }

        self::assertSame([], $found);
    }

    public function testNoExternalScriptOrStylesheet(): void
    {
        $found = [];
        foreach (self::templates() as $path => $content) {
            if (preg_match_all('/<(?:script|link)\b[^>]*(?:src|href)\s*=\s*["\'](?:https?:)?\/\/[^"\']+/i', $content, $m) > 0) {
                $found[] = $path.': '.implode(' | ', $m[0]);
            }
        }

        self::assertSame([], $found);
    }

    public function testBaseLayoutExposesTheNonceToFullCalendar(): void
    {
        self::assertStringContainsString(
            '<meta name="csp-nonce" content="{{ csp_nonce() }}">',
            self::templates()['base.html.twig'],
        );
    }

    public function testNoCssImportedFromJavascript(): void
    {
        $root  = \dirname(__DIR__, 3).'/assets';
        $found = [];
        $files = (new Finder())->files()->in($root)->exclude(['vendor', 'lib'])->name('*.js');
        foreach ($files as $file) {
            if (preg_match('/\bimport\s+(?:[^;]*?\s+from\s+)?["\'][^"\']+\.css["\']/', $file->getContents()) === 1) {
                $found[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $found);
    }
}
