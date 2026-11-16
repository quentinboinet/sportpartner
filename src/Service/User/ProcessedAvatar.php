<?php

namespace App\Service\User;

/** Photo de profil retraitée par AvatarProcessor, prête à être enregistrée (UserAvatar). */
final class ProcessedAvatar
{
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
