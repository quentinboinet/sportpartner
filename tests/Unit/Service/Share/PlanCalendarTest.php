<?php

namespace App\Tests\Unit\Service\Share;

use App\Service\Share\PlanCalendar;
use App\Service\Share\PublicPlanBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fichier .ics de la page publique : format RFC 5545 (CRLF, lignes repliées à 75 octets, échappement des TEXT,
 * UID, DTSTAMP, heures flottantes), contenu (départ, passages, arrivée), cas sans heure / sans date.
 */
final class PlanCalendarTest extends TestCase
{
    use ShareFixtures;

    private const LABELS = [
        'summary' => 'Trail des Aravis - Camille',
        'by' => 'Plan de course de Camille',
        'start' => 'Départ',
        'finish' => 'Arrivée',
        'aid' => 'Ravito',
        'day' => 'J+%n%',
        'unscheduled' => 'Horaires pas encore définis',
        'note' => "Horaires prévus d'après le plan de course : ce n'est pas un suivi en direct.",
    ];
    private const URL = 'https://sportpartner.example/p/AbCdEfGhIjKlMnOpQrStUvWxYz012345';
    private const UID = '0123456789abcdef0123456789abcdef@sportpartner.example';

    /**
     * @return array<string, mixed>
     */
    private function view(string $start = '08:00', ?int $target = 4 * 3600 + 30 * 60, ?\DateTimeImmutable $date = new \DateTimeImmutable('2027-06-12'), ?string $message = null): array
    {
        $race = self::race($date);
        $route = self::route($race);
        $cps = self::checkpoints($race);
        $cps[1]->setPublicMessage("Côté gauche, après la tente ; avec un gel, merci\\");
        $share = self::share($race)->setMessage($message);
        $sv = $this->scenarioView($race, $route, $cps, [self::scenario($race, 11, 'A', $start, $target)]);

        return (new PublicPlanBuilder())->build($race, $share, $route, $sv['checkpoints'], $sv['routeDistanceM'], $sv['scenarios'][0]);
    }

    /** @param array<string, mixed> $view */
    private static function ics(array $view, array $labels = self::LABELS): string
    {
        $ics = PlanCalendar::build($view, $labels, self::URL, self::UID, new \DateTimeImmutable('2026-11-16 10:15:30', new \DateTimeZone('Europe/Paris')));
        self::assertIsString($ics);

        return $ics;
    }

    /**
     * Lignes logiques (dépliées) : nom de propriété (avec paramètres) => valeur.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function unfold(string $ics): array
    {
        $logical = explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));

        return array_map(static function (string $line): array {
            $pos = strpos($line, ':');
            self::assertNotFalse($pos, $line);

            return [substr($line, 0, $pos), substr($line, $pos + 1)];
        }, $logical);
    }

    /** @param list<array{0: string, 1: string}> $lines */
    private static function prop(array $lines, string $name): ?string
    {
        foreach ($lines as [$key, $value]) {
            if ($key === $name) {
                return $value;
            }
        }

        return null;
    }

    private static function unescape(string $text): string
    {
        return (string) preg_replace_callback('/\\\\([\\\\;,nN])/', static fn (array $m): string => 'n' === strtolower($m[1]) ? "\n" : $m[1], $text);
    }

    #[Test]
    public function fileIsValidICalendar(): void
    {
        $ics = self::ics($this->view(message: 'Merci ; à tous, vraiment'));

        // CRLF partout, y compris à la fin ; aucun LF ni CR isolé
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $ics), 'bare LF');
        self::assertSame(0, preg_match('/\r(?!\n)/', $ics), 'bare CR');
        // Lignes physiques : 75 octets au plus, UTF-8 valide (jamais de caractère coupé), suite commençant par une espace
        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $i => $line) {
            self::assertLessThanOrEqual(75, \strlen($line), "line $i too long: $line");
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), "line $i splits a character");
        }
        self::assertGreaterThan(0, substr_count($ics, "\r\n "), 'long lines are folded');

        $lines = self::unfold($ics);
        self::assertSame(['BEGIN', 'VCALENDAR'], $lines[0]);
        self::assertSame(['END', 'VCALENDAR'], $lines[\count($lines) - 1]);
        self::assertSame('2.0', self::prop($lines, 'VERSION'));
        self::assertSame(PlanCalendar::PRODID, self::prop($lines, 'PRODID'));
        self::assertSame(1, \count(array_filter($lines, static fn (array $l): bool => $l === ['BEGIN', 'VEVENT'])));
        self::assertSame(1, \count(array_filter($lines, static fn (array $l): bool => $l === ['END', 'VEVENT'])));
        self::assertSame(self::UID, self::prop($lines, 'UID'));
        // DTSTAMP en UTC ; DTSTART / DTEND en heure locale flottante (ni Z ni TZID)
        self::assertSame('20261116T091530Z', self::prop($lines, 'DTSTAMP'));
        self::assertSame('20270612T080000', self::prop($lines, 'DTSTART'));
        self::assertSame('20270612T123000', self::prop($lines, 'DTEND'));
        self::assertStringNotContainsString('TZID', $ics);
        self::assertSame(self::URL, self::prop($lines, 'URL'));
        self::assertSame('Annecy', self::prop($lines, 'LOCATION'));
        self::assertSame('Trail des Aravis - Camille', self::prop($lines, 'SUMMARY'));
    }

    #[Test]
    public function descriptionListsThePlannedPassagesEscaped(): void
    {
        $view = $this->view(message: "Merci ; à tous,\nvraiment");
        $lines = self::unfold(self::ics($view));
        $raw = (string) self::prop($lines, 'DESCRIPTION');

        // Aucun caractère spécial non échappé dans la valeur TEXT
        self::assertSame(0, preg_match('/(?<!\\\\)[;,]/', str_replace('\\\\', '', $raw)), 'unescaped ; or ,');
        self::assertStringNotContainsString("\n", $raw);

        $text = self::unescape($raw);
        $expected = [
            'Plan de course de Camille',
            "Merci ; à tous,\nvraiment",
            'Départ : 08:00',
            'km 5,0 - Col : '.$view['points'][1]['clock']['time'],
            'km 10,0 - Ravito 1 (Ravito) : '.$view['points'][2]['clock']['time'],
            "    Côté gauche, après la tente ; avec un gel, merci\\",
            'km 20,0 - Ravito 2 (Ravito) : '.$view['points'][4]['clock']['time'],
            'Arrivée (km 25,0) : 12:30',
            "Horaires prévus d'après le plan de course : ce n'est pas un suivi en direct.",
            self::URL,
        ];
        foreach ($expected as $part) {
            self::assertStringContainsString($part, $text);
        }
        self::assertStringNotContainsString(self::SENTINELS['cpNotes'], $text);
    }

    #[Test]
    public function nextDayFinishAndDayOffsets(): void
    {
        $view = $this->view('21:30', 5 * 3600 + 10 * 60);
        $lines = self::unfold(self::ics($view));

        self::assertSame('20270612T213000', self::prop($lines, 'DTSTART'));
        self::assertSame('20270613T024000', self::prop($lines, 'DTEND'));
        self::assertStringContainsString('Arrivée (km 25,0) : 02:40 J+1', self::unescape((string) self::prop($lines, 'DESCRIPTION')));
    }

    #[Test]
    public function withoutTimesTheEventLastsTheWholeDay(): void
    {
        // Pas de temps visé ni d'heure de départ
        $race = self::race();
        $cps = self::checkpoints($race);
        $sv = $this->scenarioView($race, null, $cps, []);
        $view = (new PublicPlanBuilder())->build($race, self::share($race), null, $sv['checkpoints'], $sv['routeDistanceM'], null);
        $lines = self::unfold(self::ics($view, ['by' => null] + self::LABELS));

        self::assertSame('20270612', self::prop($lines, 'DTSTART;VALUE=DATE'));
        self::assertSame('20270613', self::prop($lines, 'DTEND;VALUE=DATE'));
        self::assertNull(self::prop($lines, 'DTSTART'));
        $text = self::unescape((string) self::prop($lines, 'DESCRIPTION'));
        self::assertStringStartsWith('Horaires pas encore définis', $text);
        self::assertStringContainsString('km 10,0 - Ravito 1 (Ravito)', $text);

        // Heure de départ sans temps visé : début à l'heure, pas de fin
        $sv = $this->scenarioView($race, null, $cps, [self::scenario($race, 51, 'A', '06:45', null)]);
        $view = (new PublicPlanBuilder())->build($race, self::share($race), null, $sv['checkpoints'], $sv['routeDistanceM'], $sv['scenarios'][0]);
        $lines = self::unfold(self::ics($view));
        self::assertSame('20270612T064500', self::prop($lines, 'DTSTART'));
        self::assertNull(self::prop($lines, 'DTEND'));
    }

    #[Test]
    public function noDateMeansNoCalendar(): void
    {
        self::assertNull(PlanCalendar::build($this->view(date: null), self::LABELS, self::URL, self::UID, new \DateTimeImmutable()));
    }

    #[Test]
    public function escapeTextFollowsRfc5545(): void
    {
        self::assertSame('a\\\\b\\;c\\,d\\ne\\nf\\ng', PlanCalendar::escapeText("a\\b;c,d\r\ne\rf\ng"));
        self::assertSame("tab\tok", PlanCalendar::escapeText("tab\tok\x00\x07\x1B\x7F"));
        self::assertSame('\\\\"><script>', PlanCalendar::escapeText('\\"><script>'));
    }

    #[Test]
    public function foldNeverSplitsAMultiByteCharacter(): void
    {
        foreach (['é', '€', '🏃', 'aé'] as $char) {
            $line = 'DESCRIPTION:'.str_repeat($char, 120);
            $folded = PlanCalendar::fold($line);
            foreach (explode("\r\n", $folded) as $i => $physical) {
                self::assertLessThanOrEqual(75, \strlen($physical));
                self::assertTrue(mb_check_encoding($physical, 'UTF-8'), $char.' line '.$i);
                if ($i > 0) {
                    self::assertStringStartsWith(' ', $physical);
                }
            }
            self::assertSame($line, str_replace("\r\n ", '', $folded));
        }
        self::assertSame('SHORT:x', PlanCalendar::fold('SHORT:x'));
        self::assertSame(str_repeat('a', 75), PlanCalendar::fold(str_repeat('a', 75)));
        self::assertSame(str_repeat('a', 75)."\r\n a", PlanCalendar::fold(str_repeat('a', 76)));
    }
}
