<?php
namespace App\Entity;

/**
 * Stratégie d'allure d'un scénario de course : elle façonne les temps AUTO du plan (jamais les temps fixés ni le
 * temps visé, qui restent exacts).
 *
 * Chaque stratégie est un multiplicateur d'allure m(x), x = position du milieu du tronçon dans la course
 * (0 = départ, 1 = arrivée). Dans la répartition proportionnelle de SplitCalculator::fillAutoFromEfforts(), le
 * poids d'un tronçon devient effort * m(x) au lieu de effort, à l'intérieur de chaque intervalle entre deux ancres
 * (départ, temps fixés, temps visé). m > 1 = plus lent (plus de temps), m < 1 = plus rapide.
 *
 *  - Régulier (even)             : m = 1 (répartition à l'effort seul, comportement historique) ;
 *  - Départ prudent (conservative): m = 1 + a * (1 - x / 0.3) pour x < 0.3, puis 1 ;
 *                                  a = 0.02 / 0.04 / 0.06 (légère / moyenne / marquée) ;
 *  - Negative split (negative)   : m = 1 + k * (0.5 - x), k = 0.03 / 0.06 / 0.10
 *                                  (moyenne : première moitié environ 1,5 % plus lente, seconde plus rapide) ;
 *  - Prise de risque (aggressive): m = 1 - k * (0.5 - x), k = 0.02 / 0.04 / 0.08
 *                                  (moyenne : départ environ 2 % plus rapide, ralentissement ensuite) ;
 *  - Gestion ultra (ultra)       : m = 1 + f * (x - 0.5), f = 0.08 / 0.15 / 0.25
 *                                  (moyenne : fin environ 15 % plus lente que le début).
 *
 * Source unique des formules et des constantes. Miroir exact (mêmes opérations flottantes) : PACING_COEFFICIENTS,
 * PACING_CONSERVATIVE_UNTIL et pacingMultiplier() de assets/controllers/race_splits_controller.js ; toute
 * modification se fait des deux côtés (PacingStrategyTest vérifie que les constantes JS sont identiques).
 */
enum PacingStrategy: string
{
    case Even = 'even';
    case Conservative = 'conservative';
    case Negative = 'negative';
    case Aggressive = 'aggressive';
    case Ultra = 'ultra';

    /** Fin de la phase prudente de "Départ prudent" : 30 % de la course. */
    public const CONSERVATIVE_UNTIL = 0.3;

    /**
     * Coefficient par stratégie et par intensité : a (Départ prudent), k (Negative split, Prise de risque),
     * f (Gestion ultra). "Régulier" n'en a pas.
     *
     * @var array<string, array<string, float>>
     */
    public const COEFFICIENTS = [
        'conservative' => ['light' => 0.02, 'medium' => 0.04, 'strong' => 0.06],
        'negative' => ['light' => 0.03, 'medium' => 0.06, 'strong' => 0.10],
        'aggressive' => ['light' => 0.02, 'medium' => 0.04, 'strong' => 0.08],
        'ultra' => ['light' => 0.08, 'medium' => 0.15, 'strong' => 0.25],
    ];

    /** Clé de traduction du libellé (race_plan.pacing_*). */
    public function labelKey(): string
    {
        return 'race_plan.pacing_'.$this->value;
    }

    /** Clé de traduction de la phrase d'explication (race_plan.pacing_*_desc). */
    public function descriptionKey(): string
    {
        return 'race_plan.pacing_'.$this->value.'_desc';
    }

    /** L'intensité change-t-elle quelque chose ? (non pour "Régulier") */
    public function usesIntensity(): bool
    {
        return self::Even !== $this;
    }

    /** Coefficient de la stratégie pour cette intensité (0 pour "Régulier"). */
    public function coefficient(PacingIntensity $intensity): float
    {
        return self::COEFFICIENTS[$this->value][$intensity->value] ?? 0.0;
    }

    /**
     * Multiplicateur d'allure au point x de la course (0 = départ, 1 = arrivée), voir les formules ci-dessus.
     * Toujours > 0 (entre 0,875 et 1,125 avec les coefficients actuels).
     */
    public function multiplier(float $x, PacingIntensity $intensity): float
    {
        $c = $this->coefficient($intensity);

        return match ($this) {
            self::Even => 1.0,
            self::Conservative => $x < self::CONSERVATIVE_UNTIL ? 1 + $c * (1 - $x / self::CONSERVATIVE_UNTIL) : 1.0,
            self::Negative => 1 + $c * (0.5 - $x),
            self::Aggressive => 1 - $c * (0.5 - $x),
            self::Ultra => 1 + $c * ($x - 0.5),
        };
    }

    /**
     * Stratégie proposée par défaut pour un nouveau scénario selon le type de course : route -> negative split,
     * trail -> départ prudent, type inconnu -> régulier (l'utilisateur peut la changer).
     */
    public static function suggestedFor(?RaceType $type): self
    {
        return match ($type) {
            RaceType::Road => self::Negative,
            RaceType::Trail => self::Conservative,
            null => self::Even,
        };
    }

    /** Valeur postée -> stratégie ; null si elle n'est pas une chaîne ou n'existe pas (comparaison exacte). */
    public static function tryFromInput(mixed $value): ?self
    {
        return \is_string($value) ? self::tryFrom($value) : null;
    }
}
