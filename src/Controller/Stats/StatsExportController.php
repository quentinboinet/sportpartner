<?php

namespace App\Controller\Stats;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Service\Activity\ActivityCsvExporter;
use App\Service\Stats\OverviewPeriod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bouton « Exporter » des statistiques : activités de la période affichée en CSV (ActivityCsvExporter), avec les
 * MÊMES paramètres que la vue d'ensemble (period=7d|month|season|all ou from/to, repli identique, OverviewPeriod).
 * Activités de l'utilisateur connecté seulement, lues en flux.
 */
#[IsGranted('ROLE_USER')]
final class StatsExportController extends AbstractController
{
    #[Route('/stats/export', name: 'app_stats_export', methods: ['GET'])]
    public function __invoke(Request $request, ActivityRepository $activities, ActivityCsvExporter $exporter, #[CurrentUser] User $user): Response
    {
        $range = OverviewPeriod::fromQuery($request->query->all(), new \DateTimeImmutable());
        $locale = $request->getLocale();

        return $exporter->response(
            $activities->exportRows($user, $range->from, $range->to),
            $locale,
            $exporter->filename($locale, $range->from, $range->to),
        );
    }
}
