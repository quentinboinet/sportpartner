<?php
namespace App\Controller\Nutrition;

use App\Repository\ActivityRepository;
use App\Repository\MealRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class NutritionController extends AbstractController
{
    #[Route('/nutrition', name: 'app_nutrition')]
    public function index(
        Request $request,
        MealRepository $mealRepo,
        ActivityRepository $activityRepo,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user    = $this->getUser();
        $profile = $user->getProfile();

        // ── Date ──────────────────────────────────────────────────────────
        $dateStr = $request->query->get('date');
        try {
            $date = $dateStr ? new \DateTimeImmutable($dateStr) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $date = new \DateTimeImmutable('today');
        }
        $date = $date->setTime(0, 0, 0);

        // ── Goals (calculated from profile or defaults) ───────────────────
        $weight       = $profile?->getWeight() ?? 70.0;
        $kcalGoal     = (int)($weight * 33);
        $carbsGoal    = (int)($kcalGoal * 0.45 / 4);
        $proteinsGoal = (int)($kcalGoal * 0.25 / 4);
        $fatsGoal     = (int)($kcalGoal * 0.30 / 9);
        $waterGoal    = 2.5;

        // ── Today's meals ─────────────────────────────────────────────────
        $meals         = $mealRepo->findByUserAndDate($user, $date);
        $totalKcal     = 0;
        $totalCarbs    = 0.0;
        $totalProteins = 0.0;
        $totalFats     = 0.0;
        foreach ($meals as $m) {
            $totalKcal     += $m->getEffectiveKcal();
            $totalCarbs    += $m->getCarbsG() ?? 0;
            $totalProteins += $m->getProteinsG() ?? 0;
            $totalFats     += $m->getFatsG() ?? 0;
        }

        // ── Today's activities & expenditure ──────────────────────────────
        $todayActivities = $activityRepo->findByUserAndDay($user, $date);
        $activityKcal    = 0;
        $hasLongRun      = false;
        foreach ($todayActivities as $act) {
            $mins          = (int)(($act->getMovingTimeSeconds() ?? 0) / 60);
            $activityKcal += $mins * 8;
            if ($act->getSport()?->getSlug() === 'run' && ($act->getDistanceMeters() ?? 0) >= 15000) {
                $hasLongRun = true;
            }
        }
        $bmr              = (int)($weight * 22);
        $totalExpenditure = $bmr + $activityKcal;
        $isTrainingDay    = count($todayActivities) > 0;

        // ── 7-day weekly data (Mon–Sun of current week) ───────────────────
        $dayOfWeek = (int)$date->format('N');
        $weekStart = $date->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
        $weekEnd   = $weekStart->modify('+6 days');

        $dailyIntake       = $mealRepo->getDailyIntake($user, $weekStart, $weekEnd);
        $dailyActivityKcal = $activityRepo->getDailyActivityKcal($user, $weekStart, $weekEnd);

        $dayKeys     = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $chartDays   = [];
        $chartIntake = [];
        $chartExp    = [];
        $weeklyNet   = 0;
        for ($i = 0; $i < 7; $i++) {
            $d         = $weekStart->modify("+{$i} days");
            $key       = $d->format('Y-m-d');
            $dayIntake = $dailyIntake[$key] ?? 0;
            $dayExp    = $bmr + ($dailyActivityKcal[$key] ?? 0);
            $chartDays[]   = $translator->trans('calendar.day_' . $dayKeys[$i]);
            $chartIntake[] = $dayIntake;
            $chartExp[]    = $dayExp;
            if ($d <= $date) {
                $weeklyNet += $dayIntake - $dayExp;
            }
        }
        $weeklyChartData = [
            'days'             => $chartDays,
            'intake'           => $chartIntake,
            'expenditure'      => $chartExp,
            'labelIntake'      => $translator->trans('nutrition.chart.intake'),
            'labelExpenditure' => $translator->trans('nutrition.chart.expenditure'),
        ];

        return $this->render('nutrition/index.html.twig', [
            'date'           => $date,
            'isTrainingDay'  => $isTrainingDay,
            'hasLongRun'     => $hasLongRun,
            'goals'          => ['kcal' => $kcalGoal, 'carbs' => $carbsGoal, 'proteins' => $proteinsGoal, 'fats' => $fatsGoal, 'water' => $waterGoal],
            'totals'         => ['kcal' => $totalKcal, 'carbs' => (int)$totalCarbs, 'proteins' => (int)$totalProteins, 'fats' => (int)$totalFats],
            'expenditure'    => ['resting' => $bmr, 'sport' => $activityKcal, 'total' => $totalExpenditure],
            'meals'          => $meals,
            'weeklyChartData' => $weeklyChartData,
            'weeklyNet'      => $weeklyNet,
        ]);
    }
}
