<?php

namespace App\Controller\Calendar;

use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CalendarController extends AbstractController
{
    #[Route('/calendar', name: 'app_calendar')]
    public function index(Request $request, ActivityRepository $activityRepo): Response
    {
        $user = $this->getUser();
        $now  = new \DateTimeImmutable();

        $year  = max(2020, min(2030, (int)($request->query->get('year',  $now->format('Y')))));
        $month = max(1,    min(12,   (int)($request->query->get('month', $now->format('m')))));

        $firstDay  = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastDay   = $firstDay->modify('last day of this month');
        $prevMonth = $firstDay->modify('-1 month');
        $nextMonth = $firstDay->modify('+1 month');

        $activities = $activityRepo->findByMonthForCalendar(
            $user,
            $firstDay->setTime(0, 0, 0),
            $lastDay->setTime(23, 59, 59)
        );

        // Group by day-of-month
        $byDay = [];
        foreach ($activities as $activity) {
            if ($activity->getStartDate()) {
                $byDay[(int)$activity->getStartDate()->format('j')][] = $activity;
            }
        }

        // Build calendar grid (Mon–Sun weeks)
        $startDow    = (int)$firstDay->format('N') - 1; // 0=Mon … 6=Sun
        $daysInMonth = (int)$lastDay->format('j');
        $prevLastDay = (int)$firstDay->modify('-1 day')->format('j');
        $totalWeeks  = (int)ceil(($startDow + $daysInMonth) / 7);
        $todayStr    = $now->format('Y-m-d');

        $weeks = [];
        for ($w = 0; $w < $totalWeeks; $w++) {
            $days = [];
            for ($col = 0; $col < 7; $col++) {
                $dn = $w * 7 + $col - $startDow + 1;
                if ($dn < 1) {
                    $days[] = ['num' => $prevLastDay + $dn, 'inMonth' => false,
                               'isToday' => false, 'isPast' => true, 'activities' => []];
                } elseif ($dn > $daysInMonth) {
                    $days[] = ['num' => $dn - $daysInMonth, 'inMonth' => false,
                               'isToday' => false, 'isPast' => false, 'activities' => []];
                } else {
                    $ds = sprintf('%04d-%02d-%02d', $year, $month, $dn);
                    $days[] = [
                        'num'        => $dn,
                        'inMonth'    => true,
                        'isToday'    => $ds === $todayStr,
                        'isPast'     => $ds < $todayStr,
                        'activities' => $byDay[$dn] ?? [],
                    ];
                }
            }
            $weeks[] = $days;
        }

        return $this->render('calendar/index.html.twig', [
            'year'           => $year,
            'month'          => $month,
            'firstDay'       => $firstDay,
            'weeks'          => $weeks,
            'prevYear'       => (int)$prevMonth->format('Y'),
            'prevMonth'      => (int)$prevMonth->format('m'),
            'nextYear'       => (int)$nextMonth->format('Y'),
            'nextMonth'      => (int)$nextMonth->format('m'),
            'totalCompleted' => count($activities),
        ]);
    }
}
