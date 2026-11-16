<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;

/**
 * Règles des lignes MANUELLES du plan nutrition quand les repères changent (calcul pur). Une ligne est attachée à un
 * point ("p:start", "p:<id>") ou au tronçon qui ARRIVE à un point ("s:<id>", "s:finish") :
 *
 *  - ajout d'un repère : rien ne bouge. Le tronçon coupé en deux garde ses apports manuels sur sa partie qui arrive
 *    au même point (identifiant inchangé) ; le nouveau tronçon (jusqu'au nouveau repère) et le nouveau point sont auto ;
 *  - déplacement d'un repère (nouvelle distance, ordre changé) : les apports du point et du tronçon qui y arrive
 *    restent attachés au repère ; le tronçon couvre simplement une autre portion du parcours ;
 *  - suppression d'un repère : rien n'est perdu. Les apports manuels du point supprimé et du tronçon qui y arrivait
 *    sont reportés sur le tronçon suivant (celui qui arrive au repère suivant dans l'ordre des distances, ou à
 *    l'arrivée), additionnés produit par produit à ce qu'il contient s'il était déjà manuel ; ce tronçon devient
 *    manuel (le bouton "Auto" le remet en calcul automatique). Un message le signale ;
 *  - repère au-delà de la fin du tracé (parcours remplacé par un plus court) : ses lignes sont gardées mais ni
 *    affichées ni comptées, comme ses temps de passage.
 * Quantités additionnées plafonnées aux limites de saisie (FuelCatalog::maxQuantity).
 *
 * @phpstan-type Line array{key: string, qty: int}
 */
final class RaceNutritionRows
{
    private function __construct()
    {
    }

    /**
     * Lignes manuelles d'un plan après la suppression du repère $deletedId.
     *
     * @param array<string, list<Line>> $manual clé de ligne => lignes (toutes les lignes manuelles du plan)
     * @param ?int                      $nextId repère suivant dans l'ordre des distances (null = arrivée)
     * @param array<string, string>     $kinds  clé de produit => "food" | "drink" (défaut : food)
     *
     * @return array{manual: array<string, list<Line>>, moved: bool, target: string}
     */
    public static function afterCheckpointDeleted(array $manual, int $deletedId, ?int $nextId, array $kinds): array
    {
        $sources = ['p:'.$deletedId, 's:'.$deletedId];
        $target = 's:'.(null === $nextId ? 'finish' : (string) $nextId);

        $moved = false;
        $lines = $manual[$target] ?? [];
        foreach ($sources as $source) {
            if (!\array_key_exists($source, $manual)) {
                continue;
            }
            $moved = true;
            $lines = self::merge($lines, $manual[$source], $kinds);
            unset($manual[$source]);
        }
        if ($moved) {
            $manual[$target] = $lines;
        }

        return ['manual' => $manual, 'moved' => $moved, 'target' => $target];
    }

    /**
     * Additionne deux listes de lignes produit par produit (ordre : celles de $into, puis les nouvelles), quantités
     * plafonnées, lignes nulles retirées.
     *
     * @param list<Line>            $into
     * @param list<Line>            $add
     * @param array<string, string> $kinds
     *
     * @return list<Line>
     */
    public static function merge(array $into, array $add, array $kinds): array
    {
        $quantities = [];
        foreach ([$into, $add] as $list) {
            foreach ($list as $line) {
                $quantities[$line['key']] = ($quantities[$line['key']] ?? 0) + max(0, $line['qty']);
            }
        }

        $merged = [];
        foreach ($quantities as $key => $qty) {
            $key = (string) $key;
            $kind = 'drink' === ($kinds[$key] ?? 'food') ? FuelKind::Drink : FuelKind::Food;
            $qty = min($qty, FuelCatalog::maxQuantity($kind));
            if ($qty > 0) {
                $merged[] = ['key' => $key, 'qty' => $qty];
            }
        }

        return $merged;
    }
}
