<?php

namespace App\Tests\Unit\Security;

use App\Security\RateLimitRetry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Yaml\Yaml;

/**
 * Réglages de sécurité : déconnexion protégée par CSRF, pare-feu « dev » réduit au profileur, choix de la langue
 * public, limiteurs de débit et délais affichés.
 */
final class SecurityConfigTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** @return array<string, mixed> */
    private static function security(): array
    {
        return Yaml::parseFile(self::ROOT.'/config/packages/security.yaml')['security'];
    }

    /** Rôles de la première règle d'access_control qui s'applique au chemin, comme le pare-feu. */
    private static function rolesFor(string $path): ?string
    {
        foreach (self::security()['access_control'] as $rule) {
            if (1 === preg_match('#'.str_replace('#', '\\#', $rule['path']).'#', $path)) {
                return $rule['roles'];
            }
        }

        return null;
    }

    public function testLogoutRequiresACsrfToken(): void
    {
        $logout = self::security()['firewalls']['main']['logout'];
        $this->assertTrue($logout['enable_csrf']);
        $this->assertSame('app_logout', $logout['path']);
        $this->assertArrayNotHasKey('csrf_token_id', $logout, 'logout_path() uses the default token id');

        // Tous les liens de déconnexion passent par logout_path() (qui ajoute le jeton)
        $links = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/templates')) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $twig = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString("path('app_logout')", $twig, $file->getPathname());
            $this->assertStringNotContainsString('href="/logout', $twig, $file->getPathname());
            $links += substr_count($twig, 'logout_path()');
        }
        $this->assertGreaterThan(0, $links);
    }

    public function testDevFirewallOnlyCoversTheProfiler(): void
    {
        $pattern = self::security()['firewalls']['dev']['pattern'];
        $this->assertSame('^/(_profiler|_wdt)/', $pattern);
        foreach (['/_profiler/abc', '/_wdt/abc'] as $path) {
            $this->assertSame(1, preg_match('#'.$pattern.'#', $path), $path);
        }
        foreach (['/css/x.css', '/images/a.png', '/js/app.js', '/settings', '/_profilerx/'] as $path) {
            $this->assertSame(0, preg_match('#'.$pattern.'#', $path), $path);
        }
    }

    public function testLocaleSwitchIsPublicAndTheRestStaysProtected(): void
    {
        $this->assertSame('PUBLIC_ACCESS', self::rolesFor('/switch-locale/en'));
        $this->assertSame('PUBLIC_ACCESS', self::rolesFor('/login'));
        $this->assertSame('PUBLIC_ACCESS', self::rolesFor('/register'));
        $this->assertSame('ROLE_USER', self::rolesFor('/switch-localex'));
        $this->assertSame('ROLE_USER', self::rolesFor('/strava/sync'));
        $this->assertSame('ROLE_USER', self::rolesFor('/settings'));
        $this->assertSame('ROLE_USER', self::rolesFor('/'));
    }

    public function testRateLimiterSettings(): void
    {
        $limiters = Yaml::parseFile(self::ROOT.'/config/packages/rate_limiter.yaml')['framework']['rate_limiter'];
        $expected = [
            'login'              => ['sliding_window', 5, '15 minutes'],
            'registration'       => ['sliding_window', 10, '1 hour'],
            'strava_sync'        => ['fixed_window', 1, '5 minutes'],
            'strava_gear_import' => ['fixed_window', 1, '10 minutes'],
            'account_delete'     => ['sliding_window', 5, '15 minutes'],
            'gpx_import'         => ['sliding_window', 10, '10 minutes'],
            'avatar'             => ['sliding_window', 10, '10 minutes'],
        ];
        $this->assertSame(array_keys($expected), array_keys($limiters));
        foreach ($expected as $name => [$policy, $limit, $interval]) {
            $this->assertSame(['policy' => $policy, 'limit' => $limit, 'interval' => $interval], $limiters[$name], $name);
            // Stockage par défaut (cache.rate_limiter) : pas de cache_pool ni de storage_service propres
            $this->assertArrayNotHasKey('cache_pool', $limiters[$name]);
        }
    }

    public function testRetryDelayIsRoundedUpToWholeMinutes(): void
    {
        $now   = 1_800_000_000;
        $limit = static fn (int $in): RateLimit => new RateLimit(0, (new \DateTimeImmutable())->setTimestamp($now + $in), false, 5);

        $this->assertSame(1, RateLimitRetry::minutes($limit(1), $now));
        $this->assertSame(1, RateLimitRetry::minutes($limit(60), $now));
        $this->assertSame(1, RateLimitRetry::minutes($limit(65), $now), 'within the 5 s rounding of the limiter');
        $this->assertSame(2, RateLimitRetry::minutes($limit(66), $now));
        $this->assertSame(5, RateLimitRetry::minutes($limit(301), $now));
        $this->assertSame(15, RateLimitRetry::minutes($limit(900), $now));
        $this->assertSame(15, RateLimitRetry::minutes($limit(901), $now));
        $this->assertSame(1, RateLimitRetry::minutes($limit(-30), $now), 'never 0');
        $this->assertSame(1, RateLimitRetry::seconds($limit(-30), $now));
        $this->assertSame(61, RateLimitRetry::seconds($limit(61), $now));
    }

    public function testTemplatesNeverReadRequestParametersDirectly(): void
    {
        // app.request.query.get() sur ?x[]=1 lève une exception dans Twig : erreur 500. Lire dans le contrôleur.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/templates')) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $twig = (string) file_get_contents($file->getPathname());
            $this->assertSame(0, preg_match('/app\.request\.(query|request)\.get\(|app\.request\.get\(/', $twig), $file->getPathname());
        }
    }
}
