<?php

namespace App\Service\Units;

class UnitConverter
{
    public function metersToKm(float $meters): float
    {
        return round($meters / 1000, 2);
    }

    public function metersToMiles(float $meters): float
    {
        return round($meters / 1609.344, 2);
    }

    public function kgToLbs(float $kg): float
    {
        return round($kg * 2.20462, 1);
    }

    public function kcalToKj(float $kcal): float
    {
        return round($kcal * 4.184, 0);
    }

    public function secondsToHMS(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) return sprintf('%d:%02d:%02d', $h, $m, $s);
        return sprintf('%d:%02d', $m, $s);
    }

    public function metersPerSecondToPaceKm(float $mps): string
    {
        if ($mps <= 0) return '--:--';
        $paceSeconds = 1000 / $mps;
        return sprintf('%d:%02d', floor($paceSeconds / 60), $paceSeconds % 60);
    }
}
