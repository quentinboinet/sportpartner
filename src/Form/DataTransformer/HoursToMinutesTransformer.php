<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Durée saisie en heures <-> minutes entières.
 *
 * Saisies acceptées (espaces ignorés) : "5", "5,5", "5.5", "5:30", "5h", "5h30" (minutes de 00 à 59
 * sur deux chiffres après ":" ou "h"). Affichage : "5" pour une heure ronde, sinon "5:30".
 *
 * @implements DataTransformerInterface<int, string>
 */
final class HoursToMinutesTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value < 0) {
            throw new TransformationFailedException('Expected a positive number of minutes.');
        }

        $hours   = intdiv($value, 60);
        $minutes = $value % 60;

        return $minutes === 0 ? (string) $hours : sprintf('%d:%02d', $hours, $minutes);
    }

    public function reverseTransform(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw new TransformationFailedException('Expected a string.');
        }
        if (trim($value) === '') {
            return null;
        }

        return self::parse($value)
            ?? throw new TransformationFailedException(sprintf('"%s" is not a duration in hours.', $value));
    }

    /** Minutes correspondant à la saisie, null si elle n'est pas une durée lisible. */
    public static function parse(string $input): ?int
    {
        $value = strtolower((string) preg_replace('/\s+/u', '', $input));

        // Heures, éventuellement décimales : "5", "5,5", "5.25"
        if (preg_match('/^(\d{1,3})(?:[.,](\d{1,3}))?$/', $value, $m) === 1) {
            return (int) round((float) ($m[1].'.'.($m[2] ?? '0')) * 60);
        }

        // Heures et minutes : "5:30", "5h", "5h30"
        if (preg_match('/^(\d{1,3})(?::([0-5]\d)|h([0-5]\d)?)$/', $value, $m) === 1) {
            $minutes = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '0');

            return (int) $m[1] * 60 + (int) $minutes;
        }

        return null;
    }
}
