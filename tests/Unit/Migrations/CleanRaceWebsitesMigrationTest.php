<?php

namespace App\Tests\Unit\Migrations;

use App\Service\Race\RaceInput;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use DoctrineMigrations\Version20240033_CleanRaceWebsites;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Migration de données Version20240033_CleanRaceWebsites : le site d'une course qui n'est pas en http(s) passe à NULL ;
 * down() ne fait rien (rien à restaurer). Le motif SQL garde exactement les sites que RaceInput::isLinkable() accepte.
 */
final class CleanRaceWebsitesMigrationTest extends TestCase
{
    private Version20240033_CleanRaceWebsites $migration;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../../migrations/Version20240033_CleanRaceWebsites.php';
        $this->migration = new Version20240033_CleanRaceWebsites($this->createStub(Connection::class), new NullLogger());
    }

    /** @return list<string> */
    private function sql(string $direction): array
    {
        $this->migration->{$direction}(new Schema());

        return array_map(static fn (Query $q): string => $q->getStatement(), $this->migration->getSql());
    }

    public function testUpNullsNonHttpWebsites(): void
    {
        $this->assertSame(
            ["UPDATE race SET website = NULL WHERE website IS NOT NULL AND LOWER(website) NOT REGEXP '^https?://[^[:space:]]+\$'"],
            $this->sql('up'),
        );
    }

    public function testDownIsANoOp(): void
    {
        $this->assertSame([], $this->sql('down'));
    }

    public function testThePatternKeepsTheSameWebsitesAsTheTemplates(): void
    {
        // [[:space:]] est aussi une classe POSIX de PCRE : même lecture que MySQL / MariaDB pour ces valeurs
        $kept = static fn (string $url): bool => 1 === preg_match('#'.Version20240033_CleanRaceWebsites::KEEP_PATTERN.'#', strtolower($url));

        foreach (['https://example.com', 'http://example.com/a?b=1', 'HTTPS://EXAMPLE.COM', 'https://trail.fr/édition-2027'] as $url) {
            $this->assertTrue($kept($url), $url);
            $this->assertTrue(RaceInput::isLinkable($url), $url);
        }
        foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,x', 'vbscript:x', 'ftp://example.com', 'example.com', '', ' https://example.com', 'https://', "https://a\nb", 'https://a b'] as $url) {
            $this->assertFalse($kept($url), $url);
            $this->assertFalse(RaceInput::isLinkable($url), $url);
        }
    }

    public function testDescriptionSaysItIsIrreversible(): void
    {
        $this->assertStringContainsString('irreversible', $this->migration->getDescription());
    }
}
