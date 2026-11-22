<?php

namespace App\Tests\Unit\Service\Route;

use App\Service\Route\GpxParser;
use App\Service\Route\InvalidGpxException;
use App\Service\Route\RouteAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GpxParserTest extends TestCase
{
    /** 1° de latitude sur la sphère R = 6371008.8 m. */
    private const DEG_LAT_M = 111195.08;

    private GpxParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GpxParser();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../fixtures/gpx/'.$name);
    }

    /**
     * @param list<string> $trkpts fragments <trkpt .../>
     */
    private function gpx11(array $trkpts, string $extra = ''): string
    {
        return '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'.$extra
            .'<trk><trkseg>'.implode('', $trkpts).'</trkseg></trk></gpx>';
    }

    /**
     * Points sur le méridien 6°E, espacés de $stepM, altitude fournie par $ele(distance).
     *
     * @return list<string>
     */
    private function meridianTrkpts(int $count, float $stepM, callable $ele): array
    {
        $pts = [];
        for ($i = 0; $i < $count; ++$i) {
            $d = $i * $stepM;
            $pts[] = sprintf('<trkpt lat="%.9F" lon="6.0"><ele>%.2F</ele></trkpt>', 45 + $d / self::DEG_LAT_M, $ele($d));
        }

        return $pts;
    }

    public function testTrailLoopFixture(): void
    {
        $route = $this->parser->parse($this->fixture('trail_loop.gpx'));

        $this->assertSame('Boucle test - Crêt du Maure', $route->name);
        // Cercle de rayon 500 m échantillonné en 126 côtés : 2·126·500·sin(π/126) ≈ 3141 m
        $this->assertEqualsWithDelta(3141, $route->distanceM, 5);
        $this->assertEqualsWithDelta(150, $route->gainM, 4);
        $this->assertEqualsWithDelta(150, $route->lossM, 4);
        $this->assertNotNull($route->minEle);
        $this->assertNotNull($route->maxEle);
        $this->assertEqualsWithDelta(500, $route->minEle, 2);
        $this->assertEqualsWithDelta(650, $route->maxEle, 2);

        // Format de stockage
        $this->assertSame(0, $route->points[0][0]);
        $last = $route->points[\count($route->points) - 1];
        $this->assertSame($route->distanceM, $last[0]);
        $prev = -1;
        foreach ($route->points as $p) {
            $this->assertCount(4, $p);
            $this->assertIsInt($p[0]);
            $this->assertGreaterThanOrEqual($prev, $p[0]);
            $prev = $p[0];
            // Le point n°40 n'a pas d'<ele> : il est interpolé, aucun null
            $this->assertIsFloat($p[1]);
            $this->assertSame(round($p[1], 1), $p[1]);
            $this->assertSame(round($p[2], 6), $p[2]);
            $this->assertSame(round($p[3], 6), $p[3]);
        }

        // Cohérence : D+ total = gainLoss de l'analyseur sur les points stockés
        $gl = (new RouteAnalyzer())->gainLoss($route->points, 0, $route->distanceM);
        $this->assertSame(['gain' => $route->gainM, 'loss' => $route->lossM], $gl);
    }

    public function testGpx10RouteWithoutElevation(): void
    {
        $route = $this->parser->parse($this->fixture('route_gpx10.gpx'));

        $this->assertSame('Itinéraire reconnaissance', $route->name);
        // 3 pas de 0,01° de latitude (1111,95 m) ; lat invalide / hors bornes ignorées ; <wpt> ignoré
        $this->assertSame([0, 1112, 2224, 3336], array_column($route->points, 0));
        $this->assertSame(3336, $route->distanceM);
        $this->assertSame([null, null, null, null], array_column($route->points, 1));
        $this->assertSame([45.0, 6.0], [$route->points[0][2], $route->points[0][3]]);
        $this->assertSame(0, $route->gainM);
        $this->assertSame(0, $route->lossM);
        $this->assertNull($route->minEle);
        $this->assertNull($route->maxEle);
    }

    public function testOneDegreeOfLatitude(): void
    {
        $route = $this->parser->parse($this->gpx11([
            '<trkpt lat="45" lon="6"/>',
            '<trkpt lat="46" lon="6"/>',
        ]));

        $this->assertSame(111195, $route->distanceM);
        $this->assertNull($route->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFixtures(): iterable
    {
        yield 'malformed XML' => ['malformed.gpx'];
        yield 'XXE (DOCTYPE + ENTITY)' => ['xxe.gpx'];
        yield 'single point' => ['single_point.gpx'];
    }

    #[DataProvider('invalidFixtures')]
    public function testInvalidFixturesAreRejected(string $file): void
    {
        $this->expectException(InvalidGpxException::class);
        $this->parser->parse($this->fixture($file));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        $pts = '<trkpt lat="45" lon="6"/><trkpt lat="45.01" lon="6"/>';
        yield 'empty' => [''];
        yield 'blank' => ["  \n "];
        yield 'not XML' => ['lat,lon\n45,6\n46,6'];
        yield 'not GPX root' => ['<?xml version="1.0"?><kml><trkpt lat="45" lon="6"/><trkpt lat="46" lon="6"/></kml>'];
        yield 'DOCTYPE without entity' => ['<?xml version="1.0"?><!DOCTYPE gpx><gpx><trk><trkseg>'.$pts.'</trkseg></trk></gpx>'];
        yield 'external DTD' => ['<?xml version="1.0"?><!DOCTYPE gpx SYSTEM "http://example.com/gpx.dtd"><gpx><trk><trkseg>'.$pts.'</trkseg></trk></gpx>'];
        yield 'no points' => ['<?xml version="1.0"?><gpx version="1.1"><metadata><name>Vide</name></metadata></gpx>'];
        yield 'zero length' => ['<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="45" lon="6"/><trkpt lat="45" lon="6"/></trkseg></trk></gpx>'];
        yield 'only invalid coords' => ['<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="0" lon="0"/><trkpt lat="x" lon="6"/><trkpt lat="45" lon="200"/></trkseg></trk></gpx>'];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreRejected(string $xml): void
    {
        $this->expectException(InvalidGpxException::class);
        $this->parser->parse($xml);
    }

    public function testOversizedInputIsRejected(): void
    {
        $xml = $this->gpx11(['<trkpt lat="45" lon="6"/>', '<trkpt lat="45.01" lon="6"/>'])
            .str_repeat(' ', GpxParser::MAX_BYTES);

        $this->expectException(InvalidGpxException::class);
        $this->expectExceptionMessage('too large');
        $this->expectExceptionCode(InvalidGpxException::TOO_LARGE);
        $this->parser->parse($xml);
    }

    public function testNamespacesAreHandled(): void
    {
        $noNs = '<?xml version="1.0"?><gpx><trk><name>Sans NS</name><trkseg>'
            .'<trkpt lat="45" lon="6"><ele>100</ele></trkpt><trkpt lat="45.01" lon="6"><ele>110</ele></trkpt>'
            .'</trkseg></trk></gpx>';
        $prefixed = '<?xml version="1.0"?><g:gpx xmlns:g="http://www.topografix.com/GPX/1/1"><g:trk><g:name>Préfixé</g:name><g:trkseg>'
            .'<g:trkpt lat="45" lon="6"><g:ele>100</g:ele></g:trkpt><g:trkpt lat="45.01" lon="6"><g:ele>110</g:ele></g:trkpt>'
            .'</g:trkseg></g:trk></g:gpx>';

        foreach (['Sans NS' => $noNs, 'Préfixé' => $prefixed] as $name => $xml) {
            $route = $this->parser->parse($xml);
            $this->assertSame($name, $route->name);
            $this->assertSame(1112, $route->distanceM);
            $this->assertSame([100.0, 110.0], array_column($route->points, 1));
            $this->assertSame(10, $route->gainM);
        }
    }

    public function testTrackPointsWinOverRouteAndSegmentsAreChained(): void
    {
        $xml = '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1">'
            .'<rte><rtept lat="10" lon="10"/><rtept lat="11" lon="10"/></rte>'
            .'<trk><name>  Trace  </name><trkseg><trkpt lat="45" lon="6"/><trkpt lat="45.01" lon="6"/></trkseg>'
            .'<trkseg><trkpt lat="45.02" lon="6"/></trkseg></trk>'
            .'<trk><trkseg><trkpt lat="45.03" lon="6"/></trkseg></trk></gpx>';

        $route = $this->parser->parse($xml);

        $this->assertSame('Trace', $route->name);
        $this->assertSame([0, 1112, 2224, 3336], array_column($route->points, 0));
    }

    public function testMissingElevationsAreInterpolatedAndExtended(): void
    {
        $route = $this->parser->parse($this->gpx11([
            '<trkpt lat="45.00" lon="6"/>',
            '<trkpt lat="45.01" lon="6"><ele>100</ele></trkpt>',
            '<trkpt lat="45.02" lon="6"><ele>abc</ele></trkpt>',
            '<trkpt lat="45.03" lon="6"><ele>120</ele></trkpt>',
            '<trkpt lat="45.04" lon="6"/>',
        ]));

        $this->assertSame([100.0, 100.0, 110.0, 120.0, 120.0], array_column($route->points, 1));
        $this->assertSame(20, $route->gainM);
        $this->assertSame(100.0, $route->minEle);
        $this->assertSame(120.0, $route->maxEle);
    }

    public function testLongElevationGapIsFilledInLinearTime(): void
    {
        // 50 000 points ~1 m, altitude seulement aux deux bouts (régression : remplissage quadratique).
        $n = 50000;
        $pts = [];
        for ($i = 0; $i < $n; ++$i) {
            $ele = 0 === $i || $n - 1 === $i ? '<ele>100</ele>' : '';
            $pts[] = sprintf('<trkpt lat="%.9F" lon="6.0">%s</trkpt>', 45 + $i / self::DEG_LAT_M, $ele);
        }

        $start = microtime(true);
        $route = $this->parser->parse($this->gpx11($pts));

        $this->assertLessThan(5.0, microtime(true) - $start);
        $this->assertSame(0, $route->gainM);
        $this->assertSame(0, $route->lossM);
        $this->assertSame([100.0], array_values(array_unique(array_column($route->points, 1))));
    }

    public function testTooManyPointsAreRejected(): void
    {
        $xml = $this->gpx11(array_fill(0, GpxParser::MAX_RAW_POINTS + 1, '<trkpt lat="45" lon="6"/>'));

        $this->expectException(InvalidGpxException::class);
        $this->expectExceptionCode(InvalidGpxException::TOO_LARGE);
        $this->parser->parse($xml);
    }

    public function testNameFallbackAndTruncation(): void
    {
        $long = str_repeat('é', 250);
        $route = $this->parser->parse($this->gpx11(
            ['<trkpt lat="45" lon="6"/>', '<trkpt lat="45.01" lon="6"/>'],
            '<metadata><name>   </name></metadata>',
        ));
        $this->assertNull($route->name);

        $xml = str_replace('<trk>', '<trk><name>'.$long.'</name>', $this->gpx11(['<trkpt lat="45" lon="6"/>', '<trkpt lat="45.01" lon="6"/>']));
        $route = $this->parser->parse($xml);
        $this->assertSame(str_repeat('é', 200), $route->name);
    }

    public function testResamplingKeepsSpacingAndSharpSummit(): void
    {
        // 2 km à 1 point/m, plat à 100 m sauf un pic pointu à 150 m à 1003 m (hors pas de 10 m).
        $ele = static fn (float $d): float => $d >= 998 && $d <= 1008 ? 150 - 10 * abs($d - 1003) : 100.0;
        $route = $this->parser->parse($this->gpx11($this->meridianTrkpts(2001, 1.0, $ele)));

        $this->assertSame(2000, $route->distanceM);
        $this->assertSame(150.0, $route->maxEle);
        $this->assertSame(100.0, $route->minEle);
        $this->assertSame(50, $route->gainM);
        $this->assertSame(50, $route->lossM);
        // ~ un point tous les 10-11 m (+ le sommet), au lieu de 2001
        $this->assertLessThanOrEqual(205, \count($route->points));
        $this->assertGreaterThanOrEqual(170, \count($route->points));

        $distances = array_column($route->points, 0);
        $this->assertContains(1003, $distances, 'Le sommet doit être conservé');
        // Seuls les points de forme du pic (pied, sommet, pied) rapprochent l'espacement sous le pas.
        $short = 0;
        for ($i = 1, $n = \count($distances); $i < $n - 1; ++$i) {
            $this->assertGreaterThan(0, $distances[$i] - $distances[$i - 1]);
            if ($distances[$i] - $distances[$i - 1] < 10) {
                ++$short;
            }
        }
        $this->assertLessThanOrEqual(4, $short);
    }

    public function testElementBombIsRefusedQuicklyBeforeBuildingTheDom(): void
    {
        // 3 millions d'éléments vides (12 Mo, sous MAX_BYTES) : libxml construirait des centaines de Mo hors memory_limit
        $xml = '<?xml version="1.0"?><gpx>'.str_repeat('<a/>', 3_000_000).'</gpx>';
        $this->assertLessThan(GpxParser::MAX_BYTES, \strlen($xml));

        $start = microtime(true);
        try {
            $this->parser->parse($xml);
            $this->fail('InvalidGpxException expected');
        } catch (InvalidGpxException $e) {
            $this->assertSame(InvalidGpxException::TOO_LARGE, $e->getCode());
            $this->assertStringContainsString('too many elements', $e->getMessage());
        }
        $this->assertLessThan(1.0, microtime(true) - $start, 'refused by counting, not by parsing');
    }

    public function testTagLimitLeavesRoomForAFullGarminTrackOfMaxPoints(): void
    {
        // Point Garmin complet : altitude, heure, extensions FC et cadence
        $garmin = '<trkpt lat="45.9044966" lon="6.1300000"><ele>500.0</ele><time>2026-05-10T07:30:00Z</time>'
            .'<extensions><gpxtpx:TrackPointExtension><gpxtpx:hr>142</gpxtpx:hr><gpxtpx:cad>84</gpxtpx:cad>'
            .'</gpxtpx:TrackPointExtension></extensions></trkpt>';
        $perPoint = substr_count($garmin, '<');
        $this->assertSame(14, $perPoint);
        $this->assertGreaterThanOrEqual(12 * GpxParser::MAX_RAW_POINTS, GpxParser::MAX_TAGS);
        // Le plus dense des GPX réels (sans heure ni extensions) dans MAX_BYTES reste sous la limite
        $minimal = '<trkpt lat="45.904497" lon="6.130000"><ele>500</ele></trkpt>';
        $this->assertLessThan(GpxParser::MAX_TAGS, intdiv(GpxParser::MAX_BYTES, \strlen($minimal)) * substr_count($minimal, '<'));

        // Juste sous la limite : le comptage ne refuse pas (le fichier est ensuite lu normalement)
        $pts = ['<trkpt lat="45" lon="6"/>', '<trkpt lat="45.01" lon="6"/>'];
        $comments = str_repeat('<!---->', 1000);
        $route = $this->parser->parse($this->gpx11($pts, $comments));
        $this->assertSame(1112, $route->distanceM);
    }

    /** @return iterable<string, array{string}> */
    public static function nonAsciiCompatibleEncodings(): iterable
    {
        $pts = '<trkpt lat="45" lon="6"/><trkpt lat="45.01" lon="6"/>';
        $xxe = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE gpx [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<gpx><metadata><name>&xxe;</name></metadata><trk><trkseg>'.$pts.'</trkseg></trk></gpx>';
        $plain = '<?xml version="1.0" encoding="UTF-16"?><gpx><trk><trkseg>'.$pts.'</trkseg></trk></gpx>';

        // UTF-16 : « <!DOCTYPE » s'écrit « <\0!\0D\0... », invisible pour une recherche d'octets
        yield 'UTF-16LE with BOM, DOCTYPE + ENTITY' => ["\xFF\xFE".(string) mb_convert_encoding($xxe, 'UTF-16LE', 'UTF-8')];
        yield 'UTF-16BE with BOM, DOCTYPE + ENTITY' => ["\xFE\xFF".(string) mb_convert_encoding($xxe, 'UTF-16BE', 'UTF-8')];
        yield 'UTF-16LE without BOM, DOCTYPE + ENTITY' => [(string) mb_convert_encoding($xxe, 'UTF-16LE', 'UTF-8')];
        yield 'UTF-16LE without DOCTYPE' => ["\xFF\xFE".(string) mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')];
        yield 'UTF-32LE' => ["\xFF\xFE\x00\x00".(string) mb_convert_encoding($plain, 'UTF-32LE', 'UTF-8')];
        // Déclaré UTF-16 mais écrit en octets simples
        yield 'declared UTF-16' => [$plain];
        yield 'declared UCS-4' => [str_replace('UTF-16', 'UCS-4', $plain)];
        yield 'NUL byte' => ['<?xml version="1.0"?><gpx><trk><trkseg>'.$pts."\0".'</trkseg></trk></gpx>'];
        yield 'not starting with <' => ['xx<?xml version="1.0"?><gpx><trk><trkseg>'.$pts.'</trkseg></trk></gpx>'];
    }

    #[DataProvider('nonAsciiCompatibleEncodings')]
    public function testOnlyAsciiCompatibleEncodingsAreParsed(string $xml): void
    {
        // Refusé AVANT libxml, par le contrôle d'encodage (pas seulement par le contrôle du DOCTYPE après parsing)
        $this->expectException(InvalidGpxException::class);
        $this->expectExceptionMessage('ASCII-compatible encoding');
        $this->parser->parse($xml);
    }

    public function testAsciiCompatibleDeclaredEncodingsStillParse(): void
    {
        $pts = '<trkpt lat="45" lon="6"/><trkpt lat="45.01" lon="6"/>';
        foreach (['UTF-8', 'utf-8', 'ISO-8859-1', 'windows-1252', 'US-ASCII'] as $encoding) {
            $xml = "\xEF\xBB\xBF".'<?xml version="1.0" encoding="'.$encoding.'"?><gpx><trk><name>Trace</name><trkseg>'.$pts.'</trkseg></trk></gpx>';
            if ('UTF-8' !== strtoupper($encoding)) {
                $xml = substr($xml, 3); // pas de BOM UTF-8 pour un autre encodage
            }
            $this->assertSame(1112, $this->parser->parse($xml)->distanceM, $encoding);
        }
        // Latin-1 : « Crêt » lu correctement
        $latin1 = '<?xml version="1.0" encoding="ISO-8859-1"?><gpx><trk><name>Cr'."\xEA".'t</name><trkseg>'.$pts.'</trkseg></trk></gpx>';
        $this->assertSame('Crêt', $this->parser->parse($latin1)->name);
    }

    public function testExistingFixturesStillParse(): void
    {
        $this->assertGreaterThan(0, $this->parser->parse($this->fixture('trail_loop.gpx'))->distanceM);
        $this->assertGreaterThan(0, $this->parser->parse($this->fixture('route_gpx10.gpx'))->distanceM);
    }

    public function testLongTrackIsCappedAroundFiveThousandPoints(): void
    {
        // 100 km à 1 point / 8 m sur le plat : pas = 100000/5000 = 20 m
        $route = $this->parser->parse($this->gpx11($this->meridianTrkpts(12501, 8.0, static fn (): float => 300.0)));

        $this->assertSame(100000, $route->distanceM);
        $this->assertLessThanOrEqual(5001, \count($route->points));
        $this->assertGreaterThanOrEqual(4000, \count($route->points));
        $this->assertSame(0, $route->gainM);
    }
}
