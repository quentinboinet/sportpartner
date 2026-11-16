<?php

namespace App\Tests\Unit\Service\Share;

use App\Entity\RaceShare;
use App\Repository\RaceShareRepository;
use App\Service\Share\ShareToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Jeton des liens publics : 192 bits aléatoires, base64url (32 caractères), nouveau jeton à chaque régénération,
 * format vérifié avant toute requête, comparaison exacte (la colonne est insensible à la casse).
 */
final class ShareTokenTest extends TestCase
{
    #[Test]
    public function generatedTokenIsUrlSafeAnd192Bits(): void
    {
        $token = ShareToken::generate();

        self::assertSame(ShareToken::LENGTH, \strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $token);
        self::assertSame($token, rawurlencode($token), 'URL-safe without encoding');
        $bytes = base64_decode(strtr($token, '-_', '+/'), true);
        self::assertIsString($bytes);
        self::assertSame(ShareToken::BYTES, \strlen($bytes));
        self::assertGreaterThanOrEqual(16, ShareToken::BYTES, 'at least 128 bits');
        self::assertTrue(ShareToken::isValid($token));
    }

    #[Test]
    public function tokensAreUnique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 2000; ++$i) {
            $tokens[] = ShareToken::generate();
        }
        self::assertCount(2000, array_unique($tokens));
        // Insensible à la casse aussi (index unique de la colonne en collation _ci)
        self::assertCount(2000, array_unique(array_map('strtolower', $tokens)));
    }

    #[Test]
    public function regenerationAlwaysGivesANewValidToken(): void
    {
        $current = ShareToken::generate();
        $seen = [$current => true];
        for ($i = 0; $i < 200; ++$i) {
            $next = ShareToken::regenerate($current);
            self::assertNotSame($current, $next);
            self::assertTrue(ShareToken::isValid($next));
            self::assertArrayNotHasKey($next, $seen);
            $seen[$next] = true;
            $current = $next;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 31)];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'plus' => [str_repeat('a', 31).'+'];
        yield 'slash' => [str_repeat('a', 31).'/'];
        yield 'padding' => [str_repeat('a', 31).'='];
        yield 'space' => [str_repeat('a', 31).' '];
        yield 'trailing newline' => [str_repeat('a', 32)."\n"];
        yield 'unicode' => [str_repeat('a', 30).'é'];
        yield 'dot' => [str_repeat('a', 31).'.'];
        yield 'sql' => ["aaaaaaaaaaaaaaaaaaaaaaaa' OR '1"];
    }

    #[Test]
    #[DataProvider('malformed')]
    public function malformedTokensAreRejectedWithoutQuery(string $token): void
    {
        self::assertFalse(ShareToken::isValid($token));

        $repo = $this->getMockBuilder(RaceShareRepository::class)->disableOriginalConstructor()->onlyMethods(['findOneBy'])->getMock();
        $repo->expects(self::never())->method('findOneBy');
        self::assertNull($repo->findActiveByToken($token));
    }

    #[Test]
    public function lookupIsExactAndOnlyForEnabledShares(): void
    {
        $token = ShareToken::generate();
        $share = (new RaceShare())->setToken($token)->setEnabled(true);

        self::assertSame($share, $this->repoReturning($share)->findActiveByToken($token));
        // Unknown or regenerated token
        self::assertNull($this->repoReturning(null)->findActiveByToken(ShareToken::generate()));
        // Disabled share: same answer as an unknown token
        self::assertNull($this->repoReturning((new RaceShare())->setToken($token)->setEnabled(false))->findActiveByToken($token));
        // Collation insensible à la casse : la base renverrait la ligne, la comparaison exacte la refuse
        $other = strtoupper($token) !== $token ? strtoupper($token) : strtolower($token);
        self::assertNull($this->repoReturning($share)->findActiveByToken($other));
    }

    private function repoReturning(?RaceShare $share): RaceShareRepository
    {
        $repo = $this->getMockBuilder(RaceShareRepository::class)->disableOriginalConstructor()->onlyMethods(['findOneBy'])->getMock();
        $repo->expects(self::once())->method('findOneBy')->willReturn($share);

        return $repo;
    }
}
