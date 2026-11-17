<?php

namespace App\Tests\Unit\Service\Activity;

use App\Service\Activity\ActivityCsvExporter;
use App\Service\Activity\ActivityExportRow;
use App\Service\Activity\CsvFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Export CSV : séparateur, décimales et en-têtes selon la langue, BOM UTF-8, CRLF, guillemets (séparateur,
 * guillemet, retour à la ligne), neutralisation des formules (OWASP), heure locale, allure / vitesse, origine,
 * nom de fichier et en-têtes HTTP.
 */
final class ActivityCsvExporterTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    private static function exporter(): ActivityCsvExporter
    {
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', self::ROOT.'/translations/messages.fr.yaml', 'fr');
        $translator->addResource('yaml', self::ROOT.'/translations/messages.en.yaml', 'en');

        return new ActivityCsvExporter($translator);
    }

    /** @param array<string, mixed> $o */
    private static function row(array $o = []): ActivityExportRow
    {
        $o += [
            'start' => '2026-11-10 06:30:00', 'timezone' => 'Europe/Paris', 'sport' => 'run', 'name' => 'Footing',
            'distance' => 10000.0, 'moving' => 3000, 'elapsed' => 3100, 'elevation' => 85.4, 'speed' => null,
            'hrAvg' => 148.6, 'hrMax' => 171.0, 'brand' => null, 'model' => null, 'nickname' => null, 'stravaId' => null, 'health' => false,
        ];

        return new ActivityExportRow(
            startUtc: new \DateTimeImmutable($o['start'], new \DateTimeZone('UTC')),
            timezone: $o['timezone'], sportSlug: $o['sport'], name: $o['name'], distanceMeters: $o['distance'],
            movingSeconds: $o['moving'], elapsedSeconds: $o['elapsed'], elevationMeters: $o['elevation'], averageSpeed: $o['speed'],
            hrAvg: $o['hrAvg'], hrMax: $o['hrMax'], gearBrand: $o['brand'], gearModel: $o['model'], gearNickname: $o['nickname'],
            stravaId: $o['stravaId'], fromHealthSession: $o['health'],
        );
    }

    /** @return list<ActivityExportRow> */
    private static function rows(): array
    {
        return [
            self::row(['timezone' => '(GMT+01:00) Europe/Paris', 'stravaId' => 16123456789, 'brand' => 'Nike', 'model' => 'Pegasus 40', 'nickname' => 'Fusée']),
            self::row(['sport' => 'ride', 'name' => '=HYPERLINK("http://evil.example","clic")', 'distance' => 42195.0, 'moving' => 5400, 'elapsed' => 5400, 'elevation' => 350.0, 'brand' => 'Autre', 'model' => 'Vélo de route']),
            self::row(['sport' => 'weight_training', 'name' => "Renfo; \"gainage\"\nséance", 'distance' => null, 'elevation' => null, 'hrAvg' => null, 'hrMax' => null, 'health' => true, 'start' => '2026-11-15 23:30:00']),
            self::row(['sport' => 'ride', 'name' => '-2+3', 'speed' => 7.9, 'stravaId' => 1]),
            self::row(['name' => '+33 6', 'sport' => null]),
            self::row(['name' => '@SUM(A1)']),
            self::row(['name' => "\tTab"]),
            self::row(['name' => "\rCR"]),
        ];
    }

    /** @return array{string, list<list<string>>} contenu brut et lignes relues */
    private static function export(string $locale): array
    {
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::exporter()->write($out, self::rows(), $locale);
        rewind($out);
        $raw = (string) stream_get_contents($out);
        rewind($out);
        fseek($out, 3);
        $lines = [];
        $sep = $locale === 'fr' ? ';' : ',';
        while (($line = fgetcsv($out, null, $sep, '"', '')) !== false) {
            $lines[] = array_map('strval', $line);
        }
        fclose($out);

        return [$raw, $lines];
    }

    #[Test]
    public function frenchExportUsesSemicolonsDecimalCommaAndBom(): void
    {
        [$raw, $lines] = self::export('fr');

        self::assertStringStartsWith("\xEF\xBB\xBF", $raw, 'BOM UTF-8');
        self::assertStringStartsWith("\xEF\xBB\xBFDate;\"Heure de départ\";Sport;Nom;\"Distance (km)\"", $raw);
        self::assertStringContainsString("\r\n", $raw, 'fins de ligne CRLF');
        self::assertSame(['Date', 'Heure de départ', 'Sport', 'Nom', 'Distance (km)', 'Temps en mouvement', 'Temps écoulé', 'D+ (m)',
            'Allure (min/km)', 'Vitesse (km/h)', 'FC moyenne (bpm)', 'FC max (bpm)', 'Matériel', 'Origine', 'Lien Strava'], $lines[0]);
        self::assertCount(9, $lines);

        // Strava, fuseau au format Strava : 06:30 UTC = 07:30 à Paris ; allure en h:mm:ss
        self::assertSame(['2026-11-10', '07:30', 'Course', 'Footing', '10,00', '0:50:00', '0:51:40', '85', '0:05:00', '', '149', '171',
            'Nike Pegasus 40 (Fusée)', 'Strava', 'https://www.strava.com/activities/16123456789'], $lines[1]);
        // Vélo : vitesse avec la virgule, pas d'allure ; formule neutralisée ; marque « Autre » non écrite
        self::assertSame(['2026-11-10', '07:30', 'Vélo', '\'=HYPERLINK("http://evil.example","clic")', '42,20', '1:30:00', '1:30:00', '350', '',
            '28,1', '149', '171', 'Vélo de route', 'Saisie manuelle', ''], $lines[2]);
        // Séance Santé, nom avec séparateur, guillemets et retour à la ligne ; date locale du lendemain
        self::assertSame(['2026-11-16', '00:30', 'Renfo', "Renfo; \"gainage\"\nséance", '', '0:50:00', '0:51:40', '', '', '', '', '', '', 'Séance santé', ''], $lines[3]);
        self::assertStringContainsString(";\"Renfo; \"\"gainage\"\"\nséance\";", $raw, 'guillemets doublés (RFC 4180)');
        // Vitesse moyenne Strava prioritaire (7,9 m/s = 28,4 km/h) ; nom « -2+3 » neutralisé
        self::assertSame(["'-2+3", '28,4', 'Strava'], [$lines[4][3], $lines[4][9], $lines[4][13]]);
        // Sans sport : « Autre » ; autres préfixes dangereux
        self::assertSame(['Autre', "'+33 6"], [$lines[5][2], $lines[5][3]]);
        self::assertSame("'@SUM(A1)", $lines[6][3]);
        self::assertSame("'\tTab", $lines[7][3]);
        self::assertSame("'\rCR", $lines[8][3]);
    }

    #[Test]
    public function englishExportUsesCommasAndDecimalPoint(): void
    {
        [$raw, $lines] = self::export('en');

        self::assertStringStartsWith("\xEF\xBB\xBFDate,\"Start time\",Sport,Name,\"Distance (km)\"", $raw);
        self::assertSame(['Date', 'Start time', 'Sport', 'Name', 'Distance (km)', 'Moving time', 'Elapsed time', 'Elevation gain (m)',
            'Pace (min/km)', 'Speed (km/h)', 'Average HR (bpm)', 'Max HR (bpm)', 'Gear', 'Source', 'Strava link'], $lines[0]);
        self::assertSame(['2026-11-10', '07:30', 'Run', 'Footing', '10.00', '0:50:00', '0:51:40', '85', '0:05:00', '', '149', '171'], array_slice($lines[1], 0, 12));
        self::assertSame(['Ride', '42.20', '28.1', 'Manual entry'], [$lines[2][2], $lines[2][4], $lines[2][9], $lines[2][13]]);
        self::assertSame(['Strength', 'Health session'], [$lines[3][2], $lines[3][13]]);
        self::assertStringContainsString(',"Renfo; ""gainage""', $raw, 'cellule avec point-virgule et guillemets entre guillemets');
    }

    /** @return iterable<string, array{string, string}> */
    public static function cells(): iterable
    {
        yield 'égal' => ['=1+1', "'=1+1"];
        yield 'plus' => ['+1', "'+1"];
        yield 'moins' => ['-1', "'-1"];
        yield 'arobase' => ['@cmd', "'@cmd"];
        yield 'tabulation' => ["\t=1", "'\t=1"];
        yield 'retour chariot' => ["\r=1", "'\r=1"];
        yield 'texte ordinaire' => ['Sortie longue', 'Sortie longue'];
        yield 'égal au milieu' => ['a=b', 'a=b'];
        yield 'vide' => ['', ''];
        yield 'nombre' => ['10,50', '10,50'];
    }

    #[Test]
    #[DataProvider('cells')]
    public function formulaPrefixesAreNeutralized(string $in, string $out): void
    {
        self::assertSame($out, CsvFormat::escapeCell($in));
    }

    #[Test]
    public function formatPerLocale(): void
    {
        self::assertSame([';', ','], [CsvFormat::forLocale('fr')->separator, CsvFormat::forLocale('fr_FR')->decimalPoint]);
        self::assertSame([',', '.'], [CsvFormat::forLocale('en')->separator, CsvFormat::forLocale('de')->decimalPoint]);
        self::assertSame('1234,57', CsvFormat::forLocale('fr')->number(1234.567, 2), 'pas de séparateur de milliers');
    }

    #[Test]
    public function filenames(): void
    {
        $e = self::exporter();
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-11-16 23:59:59');
        self::assertSame('sportpartner-activites-2026-11-01_2026-11-16.csv', $e->filename('fr', $from, $to));
        self::assertSame('sportpartner-activities-2026-11-01_2026-11-16.csv', $e->filename('en', $from, $to));
        self::assertSame('sportpartner-activites-weight-training-2026-11-16.csv', $e->filename('fr', null, $to, 'weight_training'));
        self::assertSame('sportpartner-activites-2026-11-16.csv', $e->filename('fr', null, $to, '../x'), 'slug inattendu ignoré');
    }

    #[Test]
    public function streamedResponseIsAnUncachedAttachment(): void
    {
        $e = self::exporter();
        $response = $e->response([self::row()], 'fr', 'sportpartner-activites-2026-11-01_2026-11-16.csv');

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=sportpartner-activites-2026-11-01_2026-11-16.csv', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        self::assertStringStartsWith("\xEF\xBB\xBFDate;", $body);
        self::assertSame(2, substr_count($body, "\r\n"));
    }
}
