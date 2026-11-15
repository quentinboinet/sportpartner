<?php

namespace App\Service\Route;

/** Résultat d'un téléchargement de GPX par lien. */
final class RemoteGpx
{
    public function __construct(
        public readonly string $content,
        public readonly string $fileName,
        /** URL saisie après normalisation (Dropbox, Drive) : c'est elle qui est mémorisée. */
        public readonly string $sourceUrl,
    ) {
    }
}
