<?php

namespace App\Service\Health;

/** Erreur de saisie d'un champ : clé de traduction (domaine messages) et ses paramètres (%max%...). */
final class FieldError
{
    /** @param array<string, int|string> $params */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {
    }
}
