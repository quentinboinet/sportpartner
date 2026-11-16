<?php

namespace App\Service\Nutrition;

use App\Entity\Activity;
use App\Entity\ActivityFuel;
use App\Entity\FuelKind;
use App\Repository\ActivityFuelRepository;

/** Prépare la carte Ravitaillement d'une activité (fonction Twig activity_fuel(), voir ActivityFuelExtension). */
final class ActivityFuelViewBuilder
{
    public function __construct(
        private readonly ActivityFuelRepository $repository,
        private readonly ActivityFuelCalculator $calculator,
    ) {
    }

    /** null : sport sans ravitaillement (musculation, natation), la carte n'est pas affichée. */
    public function build(Activity $activity): ?ActivityFuelView
    {
        if (!FuelCatalog::supportsSportSlug($activity->getSport()?->getSlug())) {
            return null;
        }

        $stored = array_map(static fn (ActivityFuel $row): FuelItem => $row->toItem(), $this->repository->findByActivity($activity));
        $user = $activity->getUser();
        $remembered = $user !== null ? $this->repository->findLatestCarbsByItemKey($user, FuelCatalog::builtInKeys()) : [];
        $items = self::displayItems($stored, $remembered);
        $duration = ActivityFuelCalculator::durationSeconds($activity->getMovingTimeSeconds(), $activity->getElapsedTimeSeconds());

        return new ActivityFuelView($items, $this->calculator->summarize($items, $duration));
    }

    /**
     * Lignes affichées : chaque apport du catalogue (ligne enregistrée, sinon quantité 0 avec les glucides
     * retenus pour l'utilisateur, sinon ceux du catalogue), puis les apports personnalisés enregistrés
     * (par numéro) ; aliments d'abord, boissons ensuite. Une ligne enregistrée dont la clé n'est plus
     * au catalogue est ignorée.
     *
     * @param list<FuelItem>       $stored     lignes enregistrées de l'activité
     * @param array<string, float> $remembered glucides retenus pour l'utilisateur, par clé du catalogue
     *
     * @return list<FuelItem>
     */
    public static function displayItems(array $stored, array $remembered): array
    {
        $byKey = [];
        foreach ($stored as $item) {
            $byKey[$item->key] = $item;
        }

        $items = [];
        foreach ([FuelKind::Food, FuelKind::Drink] as $kind) {
            foreach (FuelCatalog::builtInKeys($kind) as $key) {
                $row = $byKey[$key] ?? null;
                $items[] = $row !== null && $row->kind === $kind
                    ? new FuelItem($kind, $key, $row->quantity, $row->carbs)
                    : new FuelItem($kind, $key, 0, $remembered[$key] ?? FuelCatalog::defaultCarbs($key));
            }

            $custom = array_values(array_filter($stored, static fn (FuelItem $item): bool => $item->kind === $kind && $item->isCustom()));
            usort($custom, static fn (FuelItem $a, FuelItem $b): int => FuelCatalog::customNumber($a->key) <=> FuelCatalog::customNumber($b->key));
            foreach ($custom as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
