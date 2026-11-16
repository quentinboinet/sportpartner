<?php
namespace App\Entity;

/** Type de matériel suivi : chaussures (course, trail, marche, rando) ou vélo. */
enum GearType: string
{
    case Shoes = 'shoes';
    case Bike = 'bike';

    /** Clé de traduction du libellé (gear.type_*). */
    public function labelKey(): string
    {
        return 'gear.type_'.$this->value;
    }

    /** Clé de traduction du titre de groupe au pluriel (gear.group_*). */
    public function groupKey(): string
    {
        return 'gear.group_'.$this->value;
    }

    /** Classe Bootstrap Icons (la 1.11.3 n'a pas d'icône de chaussure : personnage à pied). */
    public function icon(): string
    {
        return match ($this) {
            self::Shoes => 'bi-person-walking',
            self::Bike => 'bi-bicycle',
        };
    }

    /** Durée de vie proposée par défaut, en mètres (null = usure non suivie). */
    public function defaultMaxDistanceM(): ?int
    {
        return match ($this) {
            self::Shoes => 700_000,
            self::Bike => null,
        };
    }

    /**
     * Slugs de sport (table sports) auxquels ce matériel est rattaché automatiquement.
     *
     * @return list<string>
     */
    public function sportSlugs(): array
    {
        return match ($this) {
            self::Shoes => ['run', 'trail', 'walk', 'hike'],
            self::Bike => ['ride'],
        };
    }

    /** Type de matériel rattaché automatiquement à un sport (null : aucun). */
    public static function forSportSlug(?string $slug): ?self
    {
        if ($slug === null) {
            return null;
        }
        foreach (self::cases() as $type) {
            if (in_array($slug, $type->sportSlugs(), true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Types de matériel qu'on peut choisir à la main pour une activité de ce sport.
     * "Autre" (ou sans sport) accepte tout : Strava y range par ex. le VTT ou l'elliptique.
     *
     * @return list<self>
     */
    public static function compatibleWithSportSlug(?string $slug): array
    {
        if ($slug === null || $slug === 'other') {
            return self::cases();
        }
        $type = self::forSportSlug($slug);

        return $type === null ? [] : [$type];
    }
}
