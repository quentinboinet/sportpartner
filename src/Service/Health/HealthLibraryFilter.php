<?php

namespace App\Service\Health;

use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthEquipment;
use App\Entity\HealthLevel;

/**
 * Filtres de la bibliothèque (paramètres GET de app_health) : catégorie, zone, niveau, matériel (« none » = sans
 * matériel), recherche texte (chaque mot doit apparaître dans le nom ou les muscles, sans tenir compte des
 * accents ni de la casse) et affichage des exercices masqués. Une valeur inconnue est ignorée.
 *
 * Le serveur rend toutes les cartes et masque celles qui ne correspondent pas (la page filtrée marche sans
 * JavaScript) ; health_library_controller.js applique les mêmes règles en direct (matches() : à garder identique).
 */
final class HealthLibraryFilter
{
    public const QUERY_MAX = 80;

    public function __construct(
        public readonly ?HealthCategory $category = null,
        public readonly ?HealthBodyArea $area = null,
        public readonly ?HealthLevel $level = null,
        /** Valeur de HealthEquipment, ou HealthEquipment::NONE. */
        public readonly ?string $equipment = null,
        public readonly string $query = '',
        public readonly bool $showHidden = false,
    ) {
    }

    /** @param array<string, mixed> $params */
    public static function fromQuery(array $params): self
    {
        $string = static fn (string $key): string => is_string($params[$key] ?? null) ? $params[$key] : '';
        $equipment = $string('equipment');
        if ($equipment !== HealthEquipment::NONE && HealthEquipment::tryFrom($equipment) === null) {
            $equipment = null;
        }

        return new self(
            category: HealthCategory::tryFrom($string('category')),
            area: HealthBodyArea::tryFrom($string('area')),
            level: HealthLevel::tryFrom($string('level')),
            equipment: $equipment,
            query: mb_substr(trim($string('q')), 0, self::QUERY_MAX),
            showHidden: in_array($string('hidden'), ['1', 'on', 'true'], true),
        );
    }

    public function matches(HealthExerciseView $view): bool
    {
        if ($view->hidden && !$this->showHidden) {
            return false;
        }
        if ($this->category !== null && $view->category !== $this->category) {
            return false;
        }
        if ($this->area !== null && !in_array($this->area, $view->areas, true)) {
            return false;
        }
        if ($this->level !== null && $view->level !== $this->level) {
            return false;
        }
        if ($this->equipment === HealthEquipment::NONE && !$view->needsNoEquipment()) {
            return false;
        }
        if ($this->equipment !== null && $this->equipment !== HealthEquipment::NONE
            && !in_array($this->equipment, $view->equipmentValues(), true)) {
            return false;
        }
        $haystack = $view->searchText();
        foreach (TextFold::words($this->query) as $word) {
            if (!str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    /** Un filtre autre que l'affichage des exercices masqués est actif. */
    public function isActive(): bool
    {
        return $this->category !== null || $this->area !== null || $this->level !== null
            || $this->equipment !== null || trim($this->query) !== '';
    }
}
