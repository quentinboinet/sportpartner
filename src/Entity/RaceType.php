<?php
namespace App\Entity;

/** Terrain d'une course : route ou trail. */
enum RaceType: string
{
    case Road = 'road';
    case Trail = 'trail';

    /** Clé de traduction du libellé (races.type_*). */
    public function labelKey(): string
    {
        return 'races.type_'.$this->value;
    }

    /** Classe Bootstrap Icons. */
    public function icon(): string
    {
        return match ($this) {
            self::Road => 'bi-signpost-2-fill',
            self::Trail => 'bi-tree-fill',
        };
    }
}
