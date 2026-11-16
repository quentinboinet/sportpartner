<?php

namespace App\Service\Nutrition;

/** Saisie du ravitaillement refusée en bloc (réponse 422) ; le message, technique, est renvoyé tel quel en JSON. */
final class InvalidFuelPayloadException extends \InvalidArgumentException
{
}
