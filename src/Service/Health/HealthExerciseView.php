<?php

namespace App\Service\Health;

use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthEquipment;
use App\Entity\HealthLevel;

/**
 * Exercice prêt à afficher pour un utilisateur et une langue (HealthExerciseViewBuilder) : textes choisis
 * (HealthExercise::textFor()), lien vidéo (le sien pour un exercice personnel, sa préférence pour un exercice
 * fourni) et masquage.
 *
 * Les textes d'un exercice personnel sont du TEXTE BRUT saisi par l'utilisateur : Twig les échappe, et le
 * JavaScript ne les insère qu'avec textContent.
 */
final class HealthExerciseView
{
    /**
     * @param list<HealthBodyArea>  $areas
     * @param list<HealthEquipment> $equipment
     * @param list<string>          $steps
     * @param list<string>          $mistakes
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $slug,
        public readonly bool $custom,
        /** Langue des textes affichés (attribut lang quand elle diffère de l'interface). */
        public readonly string $textLocale,
        public readonly string $name,
        public readonly HealthCategory $category,
        public readonly HealthLevel $level,
        public readonly array $areas,
        public readonly array $equipment,
        public readonly bool $unilateral,
        public readonly HealthDosageMode $mode,
        public readonly int $sets,
        public readonly int $value,
        public readonly int $rest,
        public readonly ?string $targetMuscles = null,
        public readonly ?string $startPosition = null,
        public readonly array $steps = [],
        public readonly ?string $breathing = null,
        public readonly array $mistakes = [],
        public readonly ?string $easier = null,
        public readonly ?string $harder = null,
        public readonly ?string $videoUrl = null,
        public readonly bool $hidden = false,
    ) {
    }

    /** Texte replié sur lequel porte la recherche : nom et muscles ciblés. */
    public function searchText(): string
    {
        return TextFold::fold($this->name.' '.($this->targetMuscles ?? ''));
    }

    /** Pas de matériel requis. */
    public function needsNoEquipment(): bool
    {
        return $this->equipment === [];
    }

    /** @return list<string> */
    public function areaValues(): array
    {
        return array_map(static fn (HealthBodyArea $a): string => $a->value, $this->areas);
    }

    /** @return list<string> */
    public function equipmentValues(): array
    {
        return array_map(static fn (HealthEquipment $e): string => $e->value, $this->equipment);
    }

    /**
     * Données d'un exercice pour la séance guidée (health_session_controller.js).
     *
     * @return array{id: int, name: string, lang: string, category: string, mode: string, unilateral: bool, sets: int, value: int, rest: int, steps: list<string>, breathing: ?string, url: string}
     */
    public function toPlayer(string $sheetUrl): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'lang'       => $this->textLocale,
            'category'   => $this->category->value,
            'mode'       => $this->mode->value,
            'unilateral' => $this->unilateral,
            'sets'       => $this->sets,
            'value'      => $this->value,
            'rest'       => $this->rest,
            'steps'      => $this->steps,
            'breathing'  => $this->breathing,
            'url'        => $sheetUrl,
        ];
    }
}
