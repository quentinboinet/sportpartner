<?php

namespace App\Service\Share;

use App\Entity\RaceShare;
use App\Service\Input\TextInput;

/**
 * Lecture et validation du formulaire des réglages du partage (RaceShareController::save). Calcul pur.
 *
 * Champs : display_name (une ligne, 60 caractères), scenario ("" = premier scénario, sinon id d'un scénario de la
 * course), show_paces / show_cutoffs / show_nutrition (case à cocher : "1" ou "on" = oui, absent, "" ou "0" = non,
 * autre = invalide),
 * message (1000), start_message, finish_message et messages[<checkpointId>] (500 chacun). Longueurs en caractères,
 * comptées APRÈS normalisation : fins de ligne CRLF / CR ramenées à LF (le navigateur compte un saut de ligne pour 1
 * avec maxlength mais en envoie 2), caractères de contrôle retirés, espaces de début et de fin supprimés. Texte vide =
 * null. Un champ qui n'est pas une chaîne UTF-8 valide est invalide (pas d'erreur 400).
 * messages[] : seules les clés des repères listés sont lues (les autres sont ignorées) ; un repère absent du POST
 * n'est pas modifié.
 *
 * @phpstan-type Values array{displayName: ?string, scenarioId: ?int, showPaces: bool, showCutoffs: bool, showNutrition: bool, message: ?string, startMessage: ?string, finishMessage: ?string, checkpointMessages: array<int, ?string>}
 * @phpstan-type Parsed array{values: Values, errors: array<string, string>}
 */
final class ShareSettingsParser
{
    public const ERROR_TOO_LONG = 'too_long';
    public const ERROR_INVALID = 'invalid';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $post          $request->request->all()
     * @param list<int>            $checkpointIds repères affichés dans le formulaire
     * @param list<int>            $scenarioIds   scénarios de la course
     *
     * @return Parsed errors : champ (display_name, scenario, show_paces, show_cutoffs, show_nutrition, message, start_message,
     *                finish_message, messages.<id>) => code (too_long | invalid) ; values : valeurs normalisées
     *                (même trop longues, pour réafficher la saisie)
     */
    public static function parse(array $post, array $checkpointIds, array $scenarioIds): array
    {
        $errors = [];

        $text = static function (string $field, int $max, bool $multiline) use ($post, &$errors): ?string {
            $value = self::normalize($post[$field] ?? null, $multiline);
            if (false === $value) {
                $errors[$field] = self::ERROR_INVALID;

                return null;
            }
            if (null !== $value && mb_strlen($value) > $max) {
                $errors[$field] = self::ERROR_TOO_LONG;
            }

            return $value;
        };

        $values = [
            'displayName' => $text('display_name', RaceShare::MAX_DISPLAY_NAME_LENGTH, false),
            'scenarioId' => null,
            'showPaces' => self::flag($post, 'show_paces', $errors),
            'showCutoffs' => self::flag($post, 'show_cutoffs', $errors),
            'showNutrition' => self::flag($post, 'show_nutrition', $errors),
            'message' => $text('message', RaceShare::MAX_MESSAGE_LENGTH, true),
            'startMessage' => $text('start_message', RaceShare::MAX_POINT_MESSAGE_LENGTH, true),
            'finishMessage' => $text('finish_message', RaceShare::MAX_POINT_MESSAGE_LENGTH, true),
            'checkpointMessages' => [],
        ];

        $scenario = $post['scenario'] ?? '';
        if (\is_string($scenario) && '' !== trim($scenario)) {
            $scenario = trim($scenario);
            if (ctype_digit($scenario) && \in_array((int) $scenario, $scenarioIds, true)) {
                $values['scenarioId'] = (int) $scenario;
            } else {
                $errors['scenario'] = self::ERROR_INVALID;
            }
        } elseif (!\is_string($scenario)) {
            $errors['scenario'] = self::ERROR_INVALID;
        }

        $messages = $post['messages'] ?? [];
        if (!\is_array($messages)) {
            $errors['messages'] = self::ERROR_INVALID;
            $messages = [];
        }
        foreach ($checkpointIds as $id) {
            if (!\array_key_exists($id, $messages)) {
                continue;
            }
            $value = self::normalize($messages[$id], true);
            if (false === $value) {
                $errors['messages.'.$id] = self::ERROR_INVALID;
                continue;
            }
            if (null !== $value && mb_strlen($value) > RaceShare::MAX_POINT_MESSAGE_LENGTH) {
                $errors['messages.'.$id] = self::ERROR_TOO_LONG;
            }
            $values['checkpointMessages'][$id] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Texte normalisé, null si vide, false si la valeur n'est pas une chaîne UTF-8.
     */
    public static function normalize(mixed $value, bool $multiline): string|false|null
    {
        return TextInput::normalize($value, $multiline);
    }

    /**
     * @param array<string, mixed>  $post
     * @param array<string, string> $errors
     */
    private static function flag(array $post, string $field, array &$errors): bool
    {
        $value = $post[$field] ?? null;
        if (null === $value || '' === $value || '0' === $value) {
            return false;
        }
        if ('1' === $value || 'on' === $value) {
            return true;
        }
        $errors[$field] = self::ERROR_INVALID;

        return false;
    }
}
