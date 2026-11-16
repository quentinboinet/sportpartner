<?php

namespace App\Entity;

/**
 * Matériel nécessaire à un exercice de l'espace Santé. Une liste vide signifie « sans matériel » : le tapis et le
 * mur, toujours disponibles, ne sont pas du matériel (ils sont cités dans la position de départ si besoin).
 */
enum HealthEquipment: string
{
    case Band = 'band';
    case Weights = 'weights';
    case Chair = 'chair';
    case Step = 'step';
    case SwissBall = 'swiss_ball';
    case Anchor = 'anchor';

    /** Valeur du filtre « sans matériel » de la bibliothèque (ce n'est pas un cas de l'énumération). */
    public const NONE = 'none';

    /** Clé de traduction du libellé (health.equipment.*). */
    public function labelKey(): string
    {
        return 'health.equipment.'.$this->value;
    }
}
