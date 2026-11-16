<?php

namespace App\Service\Stats;

/**
 * Période de jours entiers : du premier jour 00:00:00 au dernier jour 23:59:59, dans le fuseau des dates
 * reçues (celui de PHP dans l'application, comme WeeklyGoalProgress::weekBounds() et YEARWEEK(startDate, 1)).
 *
 * Les calculs de jours passent par les dates 'Y-m-d' converties en UTC : un passage à l'heure d'été ou
 * d'hiver (journée de 23 h ou 25 h) ne décale ni le nombre de jours ni l'indice d'un jour.
 */
final class DateRange
{
    public readonly \DateTimeImmutable $from;
    public readonly \DateTimeImmutable $to;

    public function __construct(\DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay)
    {
        $this->from = $firstDay->setTime(0, 0, 0);
        $this->to = $lastDay->setTime(23, 59, 59);

        if ($this->fromKey() > $this->toKey()) {
            throw new \InvalidArgumentException('A date range cannot end before it starts.');
        }
    }

    /** Période entre deux dates 'Y-m-d' (bornes incluses) dans le fuseau donné. */
    public static function ofDays(string $firstDay, string $lastDay, \DateTimeZone $timezone): self
    {
        return new self(new \DateTimeImmutable($firstDay, $timezone), new \DateTimeImmutable($lastDay, $timezone));
    }

    public function fromKey(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toKey(): string
    {
        return $this->to->format('Y-m-d');
    }

    /** Nombre de jours calendaires, bornes incluses. */
    public function days(): int
    {
        return self::dayNumber($this->toKey()) - self::dayNumber($this->fromKey()) + 1;
    }

    public function contains(string $day): bool
    {
        return $day >= $this->fromKey() && $day <= $this->toKey();
    }

    /** Indice (0 = premier jour) d'un jour 'Y-m-d' de la période. */
    public function indexOf(string $day): int
    {
        return self::dayNumber($day) - self::dayNumber($this->fromKey());
    }

    /** Jour 'Y-m-d' d'indice $index (0 = premier jour), sans vérifier qu'il est dans la période. */
    public function dayAt(int $index): string
    {
        return self::addDays($this->fromKey(), $index);
    }

    /** Même période, $days jours plus tôt (négatif : plus tard). */
    public function shiftDays(int $days): self
    {
        $tz = $this->from->getTimezone();

        return self::ofDays(self::addDays($this->fromKey(), -$days), self::addDays($this->toKey(), -$days), $tz);
    }

    /** Numéro de jour depuis le 01/01/1970 d'une date 'Y-m-d' (calcul en UTC, sans effet des changements d'heure). */
    public static function dayNumber(string $day): int
    {
        return intdiv((new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    /** Date 'Y-m-d' décalée de $days jours (calcul en UTC). */
    public static function addDays(string $day, int $days): string
    {
        return (new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '').$days.' days')
            ->format('Y-m-d');
    }
}
