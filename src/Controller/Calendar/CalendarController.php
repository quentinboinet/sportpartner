<?php

namespace App\Controller\Calendar;

use App\Entity\Activity;
use App\Entity\Race;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\RaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Calendrier général : activités réalisées (Strava) + courses prévues (optionnel).
 * La page ne contient que le squelette ; les événements sont chargés par période
 * via app_calendar_events (FullCalendar, chargé à la demande).
 */
#[IsGranted('ROLE_USER')]
class CalendarController extends AbstractController
{
    /** Période maximale demandée en une fois (vue Année + marges). */
    private const MAX_RANGE_DAYS = 400;

    #[Route('/calendar', name: 'app_calendar', methods: ['GET'])]
    public function index(Request $request, RaceRepository $raceRepo, #[CurrentUser] User $user): Response
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date'));

        return $this->render('calendar/index.html.twig', [
            'showRaces'     => $request->query->getBoolean('races'),
            'initialDate'   => $date ? $date->format('Y-m-d') : date('Y-m-d'),
            'upcomingRaces' => $raceRepo->findUpcoming($user, 5),
        ]);
    }

    #[Route('/calendar/events', name: 'app_calendar_events', methods: ['GET'])]
    public function events(
        Request $request,
        ActivityRepository $activityRepo,
        RaceRepository $raceRepo,
        TranslatorInterface $translator,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $from = $this->parseDate((string) $request->query->get('start'));
        $to   = $this->parseDate((string) $request->query->get('end'));
        if ($from === null || $to === null || $to <= $from || $from->diff($to)->days > self::MAX_RANGE_DAYS) {
            return $this->json(['error' => 'invalid range'], Response::HTTP_BAD_REQUEST);
        }

        $events = [];

        // Les dates Strava sont en UTC : on élargit d'un jour de chaque côté pour ne rien
        // perdre au passage en heure locale, puis on filtre sur la date locale.
        $activities = $activityRepo->findByMonthForCalendar($user, $from->modify('-1 day'), $to->modify('+1 day'));
        foreach ($activities as $activity) {
            $start = $this->localStart($activity);
            if ($start === null || $start < $from || $start >= $to) {
                continue;
            }
            $events[] = $this->activityEvent($activity, $start, $translator);
        }

        if ($request->query->getBoolean('races')) {
            foreach ($raceRepo->findDatedBetween($user, $from, $to) as $race) {
                $events[] = $this->raceEvent($race, $translator);
            }
        }

        return $this->json($events);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityEvent(Activity $activity, \DateTimeImmutable $start, TranslatorInterface $translator): array
    {
        $sport = $activity->getSport();
        $slug  = $sport?->getSlug() ?? 'other';
        $km    = $activity->getDistanceKm();

        return [
            'id'              => 'a'.$activity->getId(),
            'title'           => $activity->getName() ?: $translator->trans('sport.'.$slug),
            // Heure "flottante" (sans fuseau) : affichée telle quelle, dans le fuseau de l'activité
            'start'           => $start->format('Y-m-d\TH:i:s'),
            'url'             => $this->generateUrl('app_activity_show', ['id' => $activity->getId()]),
            'backgroundColor' => $sport?->getColor() ?? '#9CA3AF',
            'borderColor'     => $sport?->getColor() ?? '#9CA3AF',
            'classNames'      => ['sp-fc-activity'],
            'extendedProps'   => [
                'kind'       => 'activity',
                'sport'      => $slug,
                'sportLabel' => $translator->trans('sport.'.$slug),
                'icon'       => $sport?->getIcon() ?? 'bi-lightning-fill',
                'distanceKm' => ($km !== null && $km > 0) ? round($km, 1) : null,
                'seconds'    => $activity->getMovingTimeSeconds() ?? 0,
                // Vue rapide (modale) : pas de requête supplémentaire au clic
                'pace'          => ($km !== null && $km > 0) ? $activity->getPaceMinPerKm() : null,
                'speedKmh'      => ($km !== null && $km > 0) ? $activity->getSpeedKmh() : null,
                'elevationGain' => $activity->getTotalElevationGain() ? (int) round($activity->getTotalElevationGain()) : null,
                'avgHr'         => $activity->getAverageHeartrate() ? (int) round($activity->getAverageHeartrate()) : null,
                'maxHr'         => $activity->getMaxHeartrate() ? (int) round($activity->getMaxHeartrate()) : null,
                'polyline'      => $activity->getSummaryPolyline() ?: null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function raceEvent(Race $race, TranslatorInterface $translator): array
    {
        $date = $race->getRaceDate();
        $days = $date ? (int) (new \DateTimeImmutable('today'))->diff($date)->format('%r%a') : null;

        return [
            'id'            => 'r'.$race->getId(),
            'title'         => $race->getName(),
            'start'         => $date?->format('Y-m-d'),
            'allDay'        => true,
            'url'           => $this->generateUrl('app_race_plan', ['id' => $race->getId()]),
            'classNames'    => ['sp-fc-race', 'sp-fc-race--'.$race->getIntent()->value, $race->isDone() ? 'sp-fc-race--done' : ''],
            'extendedProps' => [
                'kind'          => 'race',
                'icon'          => $race->getIntent()->icon(),
                'color'         => $race->getIntent()->color(),
                'intentLabel'   => $translator->trans($race->getIntent()->labelKey()),
                'typeLabel'     => $race->getType() ? $translator->trans($race->getType()->labelKey()) : null,
                'typeIcon'      => $race->getType()?->icon(),
                'distanceKm'    => $race->getDistanceKm(),
                'elevationGain' => $race->getElevationGain(),
                // Vue rapide (modale)
                'location'      => $race->getLocation(),
                'website'       => $race->getWebsite(),   // déjà restreint à http(s) à l'enregistrement
                'notes'         => $race->getNotes(),
                'isDone'        => $race->isDone(),
                'countdown'     => match (true) {
                    $days === null, $days < 0 => null,
                    $days === 0               => $translator->trans('calendar.today'),
                    default                   => $translator->trans('calendar.in_days', ['%count%' => $days]),
                },
                'planUrl'       => $this->generateUrl('app_race_plan', ['id' => $race->getId()]),
                'listUrl'       => $this->generateUrl('app_races_show', ['id' => $race->getId()]),
            ],
        ];
    }

    /** Heure de départ dans le fuseau de l'activité (champ Strava "(GMT+01:00) Europe/Paris"). */
    private function localStart(Activity $activity): ?\DateTimeImmutable
    {
        $raw = $activity->getStartDate();
        if ($raw === null) {
            return null;
        }
        $utc = new \DateTimeImmutable($raw->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));

        $tzName = trim((string) preg_replace('/^\(.*?\)\s*/', '', (string) $activity->getTimezone()));
        try {
            $tz = new \DateTimeZone($tzName !== '' ? $tzName : date_default_timezone_get());
        } catch (\Exception) {
            $tz = new \DateTimeZone(date_default_timezone_get());
        }

        // On retire le fuseau pour comparer/afficher en heure locale "flottante"
        return new \DateTimeImmutable($utc->setTimezone($tz)->format('Y-m-d H:i:s'));
    }

    /** Accepte "2026-11-01" ou un ISO complet "2026-11-01T00:00:00+01:00" (FullCalendar). */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date ?: null;
    }
}
