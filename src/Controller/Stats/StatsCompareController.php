<?php

namespace App\Controller\Stats;

use App\Entity\Sport;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\SportRepository;
use App\Service\Stats\ComparisonFormat;
use App\Service\Stats\ComparisonPresenter;
use App\Service\Stats\PeriodComparator;
use App\Service\Stats\PeriodComparisonResolver;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Statistiques, vue « Comparer » : deux périodes (préréglage ou dates libres) et un sport, en deux requêtes
 * (la liste des sports, puis ActivityRepository::sumByDayAndSport pour A et B à la fois).
 * Paramètres GET partageables : preset, a_from, a_to, b_from, b_to, sport (voir PeriodComparisonResolver).
 */
#[IsGranted('ROLE_USER')]
class StatsCompareController extends AbstractController
{
    #[Route('/stats/comparer', name: 'app_stats_compare', methods: ['GET'])]
    public function compare(
        Request $request,
        ActivityRepository $activities,
        SportRepository $sportRepository,
        ClockInterface $clock,
        TranslatorInterface $translator,
        #[CurrentUser] User $user,
    ): Response {
        $now = $clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $locale = $request->getLocale();

        $sports = $sportRepository->findAllOrdered();
        $slugs = array_map(static fn (Sport $s): string => $s->getSlug(), $sports);
        $input = PeriodComparisonResolver::fromQuery($request->query->all(), $slugs, $now);
        $comparison = $input->comparison;

        $rows = $activities->sumByDayAndSport($user, [
            [$comparison->a->from, $comparison->a->to],
            [$comparison->b->from, $comparison->b->to],
        ]);
        $result = PeriodComparator::compare($comparison, $rows, $input->sport, $now->format('Y-m-d'), $slugs);

        $months = self::months($translator);
        $sportLabels = [];
        $sportColors = [];
        foreach ($sports as $sport) {
            $sportLabels[$sport->getSlug()] = $translator->trans('sport.'.$sport->getSlug());
            $sportColors[$sport->getSlug()] = $sport->getColor();
        }

        $sportRows = [];
        foreach ($result->sports as $row) {
            $sportRows[] = [
                'slug'    => $row['slug'],
                'color'   => $sportColors[$row['slug']] ?? '#9CA3AF',
                'metrics' => array_map(static fn ($m): array => ComparisonPresenter::metric($m, $locale), $row['metrics']),
            ];
        }

        // Liens des préréglages : le sport choisi est conservé ; « Personnalisé » part des périodes affichées
        $sportParam = $input->sport !== null ? ['sport' => $input->sport] : [];
        $customParams = [
            'preset' => PeriodComparisonResolver::CUSTOM,
            'a_from' => $comparison->a->fromKey(), 'a_to' => $comparison->a->toKey(),
            'b_from' => $comparison->b->fromKey(), 'b_to' => $comparison->b->toKey(),
        ];

        return $this->render('stats/compare.html.twig', [
            'comparison'   => $comparison,
            'year'         => (int) $now->format('Y'),
            'sport'        => $input->sport,
            'sports'       => $sports,
            'errors'       => $input->errors,
            'presets'      => PeriodComparisonResolver::PRESETS,
            'sportParam'   => $sportParam,
            'customParams' => $customParams,
            'rangeA'       => ComparisonFormat::range($comparison->a->fromKey(), $comparison->a->toKey(), $months),
            'rangeB'       => ComparisonFormat::range($comparison->b->fromKey(), $comparison->b->toKey(), $months),
            'metrics'      => ComparisonPresenter::metrics($result->metrics, $locale),
            'weekly'       => ComparisonPresenter::metrics($result->weekly, $locale),
            'sportRows'    => $sportRows,
            'empty'        => $result->a->sessions === 0 && $result->b->sessions === 0,
            'maxDay'       => PeriodComparisonResolver::maxDay($now),
            'chart'        => ComparisonPresenter::chart($result, $locale, $months, $sportLabels, $sportColors, [
                'day'  => $translator->trans('stats.compare.day_prefix'),
                'week' => $translator->trans('stats.compare.week_prefix'),
            ]),
        ]);
    }

    /**
     * Noms courts des mois, traduits (stats.compare.month_short.m1 à m12).
     *
     * @return list<string>
     */
    public static function months(TranslatorInterface $translator): array
    {
        return array_map(static fn (int $m): string => $translator->trans('stats.compare.month_short.m'.$m), range(1, 12));
    }
}
