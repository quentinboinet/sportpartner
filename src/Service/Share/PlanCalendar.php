<?php

namespace App\Service\Share;

use App\Service\Route\DurationFormat;

/**
 * Fichier iCalendar (RFC 5545) de la page publique d'un plan de course : UN événement, du départ à l'arrivée prévue
 * du scénario partagé, avec les passages prévus dans la description. Calcul pur, à partir de PublicPlanBuilder::build().
 *
 * - heures locales "flottantes" (ni TZID ni Z) : la course n'a pas de fuseau enregistré, l'agenda les affiche telles
 *   quelles ; sans heure de départ : événement sur la journée (VALUE=DATE) ; sans arrivée prévue : pas de DTEND
 *   (l'événement finit à son début, RFC 5545 3.6.1) ;
 * - textes échappés (\\, \;, \, et \n), caractères de contrôle retirés ;
 * - lignes repliées à 75 octets (coupure sur une frontière de caractère UTF-8, suite précédée d'une espace) ;
 * - fins de ligne CRLF, y compris après la dernière.
 *
 * @phpstan-import-type PublicView from PublicPlanBuilder
 *
 * @phpstan-type Labels array{summary: string, by: ?string, start: string, finish: string, aid: string, day: string, unscheduled: string, note: string}
 */
final class PlanCalendar
{
    public const LINE_OCTETS = 75;
    public const PRODID = '-//SportPartner//Race plan//FR';

    private function __construct()
    {
    }

    /**
     * @param PublicView $view
     * @param Labels     $labels textes déjà traduits ; day contient %n% (ex. "J+%n%")
     *
     * @return string|null null si la course n'a pas de date (aucun événement possible)
     */
    public static function build(array $view, array $labels, string $url, string $uid, \DateTimeImmutable $now, string $decimal = ','): ?string
    {
        $date = $view['race']['date'];
        if (null === $date) {
            return null;
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::escapeText($uid),
            'DTSTAMP:'.$now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
        ];

        $startAt = $view['points'][0]['at'] ?? null;
        if (null !== $startAt) {
            $lines[] = 'DTSTART:'.self::localDateTime($startAt);
            if (null !== $view['finishAt']) {
                $lines[] = 'DTEND:'.self::localDateTime($view['finishAt']);
            }
        } else {
            $day = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            $lines[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$day->modify('+1 day')->format('Ymd');
        }

        $lines[] = 'SUMMARY:'.self::escapeText($labels['summary']);
        if (null !== $view['race']['location']) {
            $lines[] = 'LOCATION:'.self::escapeText($view['race']['location']);
        }
        $lines[] = 'DESCRIPTION:'.self::escapeText(self::description($view, $labels, $url, $decimal));
        $lines[] = 'URL:'.$url;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * Valeur TEXT (RFC 5545 3.3.11) : \ ; , échappés, fins de ligne -> \n, autres caractères de contrôle retirés.
     */
    public static function escapeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /**
     * Repli d'une ligne de contenu (RFC 5545 3.1) : 75 octets au plus par ligne, CRLF puis une espace avant la suite,
     * jamais au milieu d'un caractère UTF-8.
     */
    public static function fold(string $line): string
    {
        $out = '';
        $limit = self::LINE_OCTETS;
        while (\strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && 0x80 === (\ord($line[$cut]) & 0xC0)) {
                --$cut; // octet de continuation : on recule jusqu'au début du caractère
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
            $limit = self::LINE_OCTETS - 1; // l'espace de tête compte dans les 75 octets
        }

        return $out.$line;
    }

    /**
     * @param PublicView $view
     * @param Labels     $labels
     */
    private static function description(array $view, array $labels, string $url, string $decimal): string
    {
        $lines = [];
        if (null !== $labels['by']) {
            $lines[] = $labels['by'];
        }
        if (null !== $view['message']) {
            $lines[] = $view['message'];
        }
        if ([] !== $lines) {
            $lines[] = '';
        }
        if (!$view['scheduled']) {
            $lines[] = $labels['unscheduled'];
        }

        foreach ($view['points'] as $point) {
            $km = number_format($point['distanceM'] / 1000, 1, $decimal, '');
            $name = match ($point['kind']) {
                PublicPlanBuilder::KIND_START => $labels['start'],
                PublicPlanBuilder::KIND_FINISH => $labels['finish'].' (km '.$km.')',
                default => 'km '.$km.' - '.$point['name'].('aid_station' === $point['type'] ? ' ('.$labels['aid'].')' : ''),
            };
            $time = null;
            if (null !== $point['clock']) {
                $time = $point['clock']['time'];
                if ($point['clock']['dayOffset'] > 0) {
                    $time .= ' '.str_replace('%n%', (string) $point['clock']['dayOffset'], $labels['day']);
                }
            } elseif (null !== $point['elapsedSeconds'] && PublicPlanBuilder::KIND_START !== $point['kind']) {
                $time = '+'.DurationFormat::format($point['elapsedSeconds']);
            }
            $lines[] = null !== $time ? $name.' : '.$time : $name;
            if (null !== $point['message']) {
                foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $point['message'])) as $messageLine) {
                    $lines[] = '    '.$messageLine;
                }
            }
        }

        $lines[] = '';
        $lines[] = $labels['note'];
        $lines[] = $url;

        return implode("\n", $lines);
    }

    /** "2026-11-16T09:48:00" -> "20261116T094800" (heure flottante). */
    private static function localDateTime(string $at): string
    {
        return str_replace(['-', ':'], '', $at);
    }
}
