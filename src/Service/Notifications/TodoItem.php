<?php

namespace App\Service\Notifications;

/**
 * Élément de la liste « À faire » (cloche de la barre du haut), calculé par TodoBuilder.
 *
 * $key est stable et contient l'état de l'alerte (« gear:12:warn » puis « gear:12:worn ») : une alerte
 * masquée dans le navigateur revient quand la situation change. Lien : route + paramètres + ancre.
 */
final class TodoItem
{
    public const URGENT = 'urgent';
    public const WARNING = 'warning';
    public const INFO = 'info';
    /** Bonne nouvelle (objectif atteint) : rangée avec les informations. */
    public const SUCCESS = 'success';

    /**
     * @param array<string, int|string> $routeParams
     */
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        /** Classe Bootstrap Icons (« bi-flag-fill »). */
        public readonly string $icon,
        public readonly TodoText $title,
        public readonly ?TodoText $subtitle,
        public readonly string $route,
        public readonly array $routeParams = [],
        /** Ancre de la page cible, sans « # ». */
        public readonly ?string $fragment = null,
    ) {
    }
}
