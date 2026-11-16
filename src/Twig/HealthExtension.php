<?php

namespace App\Twig;

use App\Entity\Activity;
use App\Entity\HealthDosageMode;
use App\Entity\HealthSession;
use App\Repository\HealthSessionRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Espace Santé dans Twig :
 *  - health_session_for_activity(activity) : séance Santé qui a créé l'activité (une requête), null sinon ;
 *  - health_dose(sets, value, mode, unilateral) : « 3 × 12 rép. chaque côté », « 3 × 30 s » ;
 *  - |health_seconds : « 45 s », « 1 min », « 1 min 30 s » ;
 *  - |health_duration : durée d'une séance, « 40 s », « 25 min », « 1 h 05 ».
 */
class HealthExtension extends AbstractExtension
{
    public function __construct(
        private readonly HealthSessionRepository $sessions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('health_session_for_activity', $this->sessionForActivity(...)),
            new TwigFunction('health_dose', $this->dose(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('health_seconds', $this->seconds(...)),
            new TwigFilter('health_duration', $this->duration(...)),
        ];
    }

    public function sessionForActivity(Activity $activity): ?HealthSession
    {
        return $activity->getId() !== null ? $this->sessions->findOneByActivity($activity) : null;
    }

    public function dose(int $sets, int $value, HealthDosageMode|string $mode, bool $unilateral = false): string
    {
        $mode = $mode instanceof HealthDosageMode ? $mode : (HealthDosageMode::tryFrom($mode) ?? HealthDosageMode::Reps);
        $text = $mode === HealthDosageMode::Reps
            ? $this->translator->trans('health.dose.reps', ['%sets%' => $sets, '%value%' => $value])
            : $this->translator->trans('health.dose.time', ['%sets%' => $sets, '%value%' => $this->seconds($value)]);

        return $unilateral ? $text.' '.$this->translator->trans('health.dose.each_side') : $text;
    }

    public function seconds(int $seconds): string
    {
        $m = intdiv(max(0, $seconds), 60);
        $s = max(0, $seconds) % 60;

        return match (true) {
            $m === 0 => $this->translator->trans('health.time.seconds', ['%s%' => $s]),
            $s === 0 => $this->translator->trans('health.time.minutes', ['%m%' => $m]),
            default  => $this->translator->trans('health.time.minutes_seconds', ['%m%' => $m, '%s%' => $s]),
        };
    }

    public function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $this->translator->trans('health.time.seconds', ['%s%' => max(0, $seconds)]);
        }
        $minutes = intdiv($seconds + 30, 60);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h === 0
            ? $this->translator->trans('health.time.minutes', ['%m%' => $m])
            : $this->translator->trans('health.time.hours_minutes', ['%h%' => $h, '%m%' => sprintf('%02d', $m)]);
    }
}
