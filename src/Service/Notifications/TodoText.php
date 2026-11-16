<?php

namespace App\Service\Notifications;

/**
 * Texte à traduire d'un élément « À faire » : clé du domaine messages et paramètres (%nom%), traduits par
 * TodoPresenter. Un paramètre est une valeur brute (texte inséré tel quel, nombre mis en forme selon la
 * langue), un autre TodoText (traduit à son tour) ou une liste de TodoText (« 12 km, 1 séance et 300 m D+ »).
 */
final class TodoText
{
    /**
     * @param array<string, string|int|float|TodoText|list<TodoText>> $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        /** Traduction mise en minuscules (« Objectif vélo atteint »). */
        public readonly bool $lowercase = false,
    ) {
    }
}
