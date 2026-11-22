<?php

namespace App\Tests\Unit\Service\Race;

use App\Entity\Race;
use App\Entity\RaceIntent;
use App\Entity\RaceType;
use App\Service\Race\RaceInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Formulaire d'une course : tout est validé avant d'écrire, jamais d'erreur 500 ni de texte tronqué en silence. */
final class RaceInputTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'name' => 'Trail du Semnoz', 'location' => 'Annecy', 'website' => 'trail-semnoz.fr', 'notes' => "Ligne 1\r\nLigne 2",
            'distance_km' => '42,195', 'elevation_gain' => '2500', 'intent' => 'registered', 'race_type' => 'trail',
            'race_date' => '2027-06-12', 'year' => '2026',
        ];
    }

    /** @return array<string, mixed> */
    private static function values(array $post): array
    {
        $parsed = RaceInput::parse($post, 2026);
        self::assertArrayHasKey('values', $parsed, json_encode($parsed) ?: '');

        return $parsed['values'];
    }

    public function testValidInput(): void
    {
        $v = self::values(self::valid());

        $this->assertSame('Trail du Semnoz', $v['name']);
        $this->assertSame('Annecy', $v['location']);
        $this->assertSame('https://trail-semnoz.fr', $v['website']);
        $this->assertSame("Ligne 1\nLigne 2", $v['notes']);
        $this->assertSame(42.195, $v['distanceKm']);
        $this->assertSame(2500, $v['elevationGain']);
        $this->assertSame(RaceIntent::Registered, $v['intent']);
        $this->assertSame(RaceType::Trail, $v['type']);
        $this->assertSame('2027-06-12', $v['raceDate']?->format('Y-m-d'));
        $this->assertSame(2027, $v['year'], 'the date sets the year');
    }

    public function testEmptyOptionalFields(): void
    {
        $v = self::values(['name' => 'Course', 'location' => '', 'website' => ' ', 'notes' => '', 'distance_km' => '', 'elevation_gain' => '', 'race_date' => '', 'year' => '2028']);

        $this->assertNull($v['location']);
        $this->assertNull($v['website']);
        $this->assertNull($v['notes']);
        $this->assertNull($v['distanceKm']);
        $this->assertNull($v['elevationGain']);
        $this->assertNull($v['raceDate']);
        $this->assertNull($v['intent']);
        $this->assertNull($v['type']);
        $this->assertSame(2028, $v['year']);
        $this->assertSame(2026, self::values(['name' => 'Course', 'year' => ['x']])['year'], 'array year: default');
        $this->assertSame(2100, self::values(['name' => 'Course', 'year' => '9999'])['year']);
    }

    public function testBoundsAreInclusive(): void
    {
        $v = self::values(['name' => str_repeat('é', 200), 'notes' => str_repeat('n', 5000), 'distance_km' => '1000', 'elevation_gain' => '20000'] + self::valid());
        $this->assertSame(1000.0, $v['distanceKm']);
        $this->assertSame(20000, $v['elevationGain']);
        $this->assertSame(0.0, self::values(['distance_km' => '0'] + self::valid())['distanceKm']);
        $this->assertSame(0, self::values(['elevation_gain' => '0'] + self::valid())['elevationGain']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'no name' => [['name' => '  '], 'races.flash_name_required'];
        yield 'name as array' => [['name' => ['x']], 'races.flash_text_invalid'];
        yield 'name 201 chars' => [['name' => str_repeat('a', 201)], 'races.flash_text_invalid'];
        yield 'name not UTF-8' => [['name' => "Trail \xFF"], 'races.flash_text_invalid'];
        yield 'location 201 chars' => [['location' => str_repeat('a', 201)], 'races.flash_text_invalid'];
        yield 'notes 5001 chars' => [['notes' => str_repeat('a', 5001)], 'races.flash_text_invalid'];
        yield 'notes not UTF-8' => [['notes' => "a\xC0b"], 'races.flash_text_invalid'];
        yield 'website javascript' => [['website' => 'javascript:alert(1)'], 'races.flash_website_invalid'];
        yield 'website data' => [['website' => 'data:text/html,<script>alert(1)</script>'], 'races.flash_website_invalid'];
        yield 'website ftp' => [['website' => 'ftp://example.com/'], 'races.flash_website_invalid'];
        yield 'website with space' => [['website' => 'https://exa mple.com'], 'races.flash_website_invalid'];
        yield 'website too long' => [['website' => 'https://example.com/'.str_repeat('a', 490)], 'races.flash_website_invalid'];
        yield 'website not UTF-8' => [['website' => "https://example.com/\xFF"], 'races.flash_website_invalid'];
        yield 'distance negative' => [['distance_km' => '-5'], 'races.flash_distance_invalid'];
        yield 'distance too big' => [['distance_km' => '1000.1'], 'races.flash_distance_invalid'];
        yield 'distance text' => [['distance_km' => 'abc'], 'races.flash_distance_invalid'];
        yield 'distance exponent' => [['distance_km' => '1e3'], 'races.flash_distance_invalid'];
        yield 'elevation decimal' => [['elevation_gain' => '1200.5'], 'races.flash_elevation_invalid'];
        yield 'elevation negative' => [['elevation_gain' => '-1'], 'races.flash_elevation_invalid'];
        yield 'elevation too big' => [['elevation_gain' => '20001'], 'races.flash_elevation_invalid'];
        yield 'date overflow' => [['race_date' => '2027-02-31'], 'races.flash_date_invalid'];
        yield 'date format' => [['race_date' => '12/06/2027'], 'races.flash_date_invalid'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRefused(array $override, string $error): void
    {
        $this->assertSame(['error' => $error], RaceInput::parse($override + self::valid(), 2026));
    }

    /** Champ numérique envoyé en tableau : ignoré, sans erreur 400 */
    public function testArrayDistanceIsIgnored(): void
    {
        $this->assertNull(self::values(['distance_km' => ['42']] + self::valid())['distanceKm']);
    }

    public function testLinkableWebsite(): void
    {
        foreach (['https://example.com', 'HTTP://EXAMPLE.COM/a?b=1'] as $url) {
            $this->assertTrue(RaceInput::isLinkable($url), $url);
            $this->assertSame($url, (new Race())->setWebsite($url)->getLinkableWebsite());
        }
        foreach ([null, '', 'javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,x', 'ftp://example.com', 'example.com', ' https://example.com', 'https://', 'https://a b'] as $url) {
            $this->assertFalse(RaceInput::isLinkable($url), (string) $url);
            $this->assertNull((new Race())->setWebsite($url)->getLinkableWebsite(), (string) $url);
        }
    }

    public function testTemplatesLinkOnlyTheLinkableWebsite(): void
    {
        foreach (['templates/race/index.html.twig', 'templates/race/show.html.twig'] as $file) {
            $twig = (string) file_get_contents(__DIR__.'/../../../../'.$file);
            $this->assertStringNotContainsString('href="{{ race.website }}"', $twig, $file);
            $this->assertStringContainsString('href="{{ race.linkableWebsite }}"', $twig, $file);
        }
    }
}
