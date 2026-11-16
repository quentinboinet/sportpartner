<?php

namespace App\Service\Health;

/**
 * Validation du JSON d'une séance guidée terminée (POST app_health_session_save) :
 *
 *   {"name": "Renfo du mardi" | null, "startedAt": 1763452800, "timezone": "Europe/Paris" | null,
 *    "durationSeconds": 1500, "elapsedSeconds": 1620, "rpe": 6 | null, "notes": "..." | null,
 *    "addActivity": true, "sport": "weight_training" | "other" | null,
 *    "exercises": [{"id": 12, "sets": 3, "value": 12, "rest": 45, "performed": [12, 12, 10]}]}
 *
 *  - name : 80 caractères au plus (espaces normalisés), vide ou null = nom par défaut ;
 *  - startedAt : horodatage Unix (s) du début, au plus 48 h dans le passé et 5 min dans le futur ;
 *  - timezone : fuseau IANA du navigateur, ignoré s'il est inconnu ;
 *  - durationSeconds : durée active, 1 s à 6 h ; elapsedSeconds : de durationSeconds à 24 h ;
 *  - rpe : entier de 1 à 10, ou null ; notes : 1 000 caractères au plus (retours à la ligne gardés) ;
 *  - sport : sport de l'activité créée, null = règle automatique ;
 *  - exercises : 1 à 30, dans l'ordre fait ; id > 0 ; sets 1 à 10 ; value 1 à 600 (bornes du mode vérifiées à
 *    l'enregistrement, HealthSessionFactory) ; rest 0 à 600 ; performed : au plus sets valeurs, chacune de 1 à value ;
 *  - au moins une série faite dans toute la séance.
 * Clé inconnue ou type inattendu : toute la séance est refusée (InvalidHealthPayloadException).
 */
final class HealthSessionPayloadValidator
{
    private const KEYS = ['name', 'startedAt', 'timezone', 'durationSeconds', 'elapsedSeconds', 'rpe', 'notes', 'addActivity', 'sport', 'exercises'];
    private const EXERCISE_KEYS = ['id', 'sets', 'value', 'rest', 'performed'];
    private const VALUE_MAX = 600;

    /** @throws InvalidHealthPayloadException */
    public function validate(mixed $payload, \DateTimeImmutable $now): HealthSessionPayload
    {
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw self::invalid('Expected an object.');
        }
        $unknown = array_diff(array_keys($payload), self::KEYS);
        if ($unknown !== []) {
            throw self::invalid(sprintf('Unknown key "%s".', mb_substr((string) reset($unknown), 0, 40)));
        }

        $name = $payload['name'] ?? null;
        if ($name !== null && !is_string($name)) {
            throw self::invalid('name must be a string or null.');
        }
        $name = HealthExerciseForm::line((string) $name);
        if (mb_strlen($name) > HealthLimits::SESSION_NAME_MAX) {
            throw self::invalid(sprintf('name is too long (max %d).', HealthLimits::SESSION_NAME_MAX));
        }

        $started = self::int($payload, 'startedAt', 0, PHP_INT_MAX);
        $nowTs = $now->getTimestamp();
        if ($started < $nowTs - HealthLimits::START_MAX_AGE || $started > $nowTs + HealthLimits::START_FUTURE_TOLERANCE) {
            throw new InvalidHealthPayloadException('invalid_start', 'startedAt is out of range.');
        }

        $timezone = $payload['timezone'] ?? null;
        if ($timezone !== null && !is_string($timezone)) {
            throw self::invalid('timezone must be a string or null.');
        }
        if ($timezone !== null && (strlen($timezone) > 64 || !in_array($timezone, \DateTimeZone::listIdentifiers(), true))) {
            $timezone = null;
        }

        $duration = self::int($payload, 'durationSeconds', 1, HealthLimits::DURATION_MAX);
        $elapsed = self::int($payload, 'elapsedSeconds', $duration, HealthLimits::ELAPSED_MAX);

        $rpe = null;
        if (($payload['rpe'] ?? null) !== null) {
            $rpe = self::int($payload, 'rpe', HealthLimits::RPE_MIN, HealthLimits::RPE_MAX);
        }

        $notes = $payload['notes'] ?? null;
        if ($notes !== null && !is_string($notes)) {
            throw self::invalid('notes must be a string or null.');
        }
        $notes = self::notes((string) $notes);
        if (mb_strlen($notes) > HealthLimits::NOTES_MAX) {
            throw self::invalid(sprintf('notes is too long (max %d).', HealthLimits::NOTES_MAX));
        }

        if (!is_bool($payload['addActivity'] ?? null)) {
            throw self::invalid('addActivity must be a boolean.');
        }

        $sport = $payload['sport'] ?? null;
        if ($sport !== null && !in_array($sport, [HealthSessionPayload::SPORT_STRENGTH, HealthSessionPayload::SPORT_OTHER], true)) {
            throw self::invalid('sport must be "weight_training", "other" or null.');
        }

        $rawExercises = $payload['exercises'] ?? null;
        if (!is_array($rawExercises) || !array_is_list($rawExercises) || $rawExercises === []) {
            throw self::invalid('exercises must be a non-empty list.');
        }
        if (count($rawExercises) > HealthLimits::SESSION_EXERCISES_MAX) {
            throw self::invalid(sprintf('Too many exercises (max %d).', HealthLimits::SESSION_EXERCISES_MAX));
        }
        $exercises = [];
        $done = 0;
        foreach ($rawExercises as $i => $raw) {
            $exercise = self::exercise($raw, "exercises[$i]");
            $done += count($exercise->performed);
            $exercises[] = $exercise;
        }
        if ($done === 0) {
            throw new InvalidHealthPayloadException('nothing_done', 'No set was completed.');
        }

        return new HealthSessionPayload(
            name: $name !== '' ? $name : null,
            startedAt: new \DateTimeImmutable('@'.$started),
            timezone: $timezone,
            durationSeconds: $duration,
            elapsedSeconds: $elapsed,
            rpe: $rpe,
            notes: $notes !== '' ? $notes : null,
            addActivity: $payload['addActivity'],
            sport: $sport,
            exercises: $exercises,
        );
    }

    private static function exercise(mixed $raw, string $path): HealthSessionPayloadExercise
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw self::invalid("$path must be an object.");
        }
        $unknown = array_diff(array_keys($raw), self::EXERCISE_KEYS);
        if ($unknown !== []) {
            throw self::invalid(sprintf('%s: unknown key "%s".', $path, mb_substr((string) reset($unknown), 0, 40)));
        }

        $id = self::int($raw, 'id', 1, PHP_INT_MAX, $path);
        $sets = self::int($raw, 'sets', HealthLimits::SETS_MIN, HealthLimits::SETS_MAX, $path);
        $value = self::int($raw, 'value', 1, self::VALUE_MAX, $path);
        $rest = self::int($raw, 'rest', HealthLimits::REST_MIN, HealthLimits::REST_MAX, $path);

        $performed = $raw['performed'] ?? null;
        if (!is_array($performed) || !array_is_list($performed) || count($performed) > $sets) {
            throw self::invalid("$path.performed must be a list of at most $sets values.");
        }
        foreach ($performed as $j => $done) {
            if (!is_int($done) || $done < 1 || $done > $value) {
                throw self::invalid("$path.performed[$j] must be an integer from 1 to $value.");
            }
        }

        return new HealthSessionPayloadExercise($id, $sets, $value, $rest, $performed);
    }

    /** @param array<array-key, mixed> $data */
    private static function int(array $data, string $key, int $min, int $max, string $path = ''): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::invalid(sprintf('%s%s must be an integer from %d to %d.', $path !== '' ? $path.'.' : '', $key, $min, $max));
        }

        return $value;
    }

    /** Notes : retours à la ligne gardés (\r\n -> \n, deux au plus d'affilée), autres caractères de contrôle retirés. */
    private static function notes(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function invalid(string $message): InvalidHealthPayloadException
    {
        return new InvalidHealthPayloadException('invalid_payload', $message);
    }
}
