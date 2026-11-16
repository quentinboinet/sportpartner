<?php

namespace App\Twig;

use App\Service\Route\DurationFormat;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Filtres d'affichage des temps du plan de course (délèguent à DurationFormat) :
 *   {{ 11730|race_duration }}        -> "3:15"
 *   {{ 11730|race_duration(true) }}  -> "3:15:30"
 *   {{ 11730|race_duration_input }}  -> "3:15:30" (format qui recharge exactement la valeur)
 *   {{ 307.4|race_pace }}            -> "5:07"
 * Une valeur null donne une chaîne vide.
 *
 * Fonction :
 *   {{ race_clock(scenario.startTime, 11730) }} -> {time: "10:15", dayOffset: 0} (heure atteinte depuis le départ,
 *   DurationFormat::formatClock), null sans heure de départ ou sans temps.
 */
final class RaceDurationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('race_duration', static fn (?int $s, bool $withSeconds = false): string => null === $s ? '' : DurationFormat::format($s, $withSeconds)),
            new TwigFilter('race_duration_input', static fn (?int $s): string => null === $s ? '' : DurationFormat::formatInput($s)),
            new TwigFilter('race_pace', static fn (int|float|null $p): string => null === $p ? '' : DurationFormat::formatPace((float) $p)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('race_clock', self::clock(...)),
        ];
    }

    /** @return array{time: string, dayOffset: int}|null */
    public static function clock(?\DateTimeImmutable $start, ?int $elapsed): ?array
    {
        return null === $start || null === $elapsed ? null : DurationFormat::formatClock($start, $elapsed);
    }
}
