<?php

namespace App\Service\Health;

/** Séance refusée : $errorCode est renvoyé tel quel au navigateur (422 {"error": errorCode}). */
final class InvalidHealthPayloadException extends \InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
