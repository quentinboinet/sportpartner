<?php

namespace App\Service\User;

use App\Service\Input\TextInput;

/**
 * Formulaire d'inscription (RegisterController), validé AVANT tout hachage du mot de passe. Calcul pur.
 *
 * - E-mail : obligatoire, sans espaces autour, UTF-8, 180 caractères au plus (colonne users.email), format du champ
 *   HTML type="email" (même motif que le mode html5 de la contrainte Email de Symfony : un point dans le domaine).
 * - Prénom, nom : facultatifs, une ligne, UTF-8, 100 caractères au plus (colonnes users.firstName / lastName).
 * - Mot de passe : jamais modifié (ni trim ni normalisation), UTF-8, PASSWORD_MIN caractères au moins,
 *   PASSWORD_MAX_BYTES octets au plus (au-delà, le hacheur de Symfony lève une exception), différent de l'e-mail
 *   (sans tenir compte de la casse ni des espaces autour).
 * - Un champ envoyé en tableau (`email[]=x`) est invalide, sans erreur 400.
 *
 * L'unicité de l'e-mail est vérifiée par le contrôleur (base de données). Erreurs : clés de traduction par champ.
 */
final class RegistrationForm
{
    public const NAME_MAX           = 100;
    public const EMAIL_MAX          = 180;
    public const PASSWORD_MIN       = 10;
    public const PASSWORD_MAX_BYTES = 4096;

    /** Motif html5 de Symfony\Component\Validator\Constraints\EmailValidator (constante privée de Symfony). */
    private const EMAIL_PATTERN = '/^[a-zA-Z0-9.!#$%&\'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/D';

    /**
     * @param array<string, string> $errors champ => clé de traduction
     */
    private function __construct(
        public readonly string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        #[\SensitiveParameter] public readonly string $password,
        public readonly array $errors,
    ) {
    }

    /**
     * @param array<mixed> $post
     */
    public static function fromPost(array $post): self
    {
        $errors = [];

        $email = TextInput::field($post, 'email', self::EMAIL_MAX);
        if (null === $email) {
            $errors['email'] = 'auth.register.error_email_required';
        } elseif (false === $email) {
            $errors['email'] = 'auth.register.error_email_too_long';
        } elseif (1 !== preg_match(self::EMAIL_PATTERN, $email)) {
            $errors['email'] = 'auth.register.error_email_invalid';
        }

        $firstName = TextInput::field($post, 'firstName', self::NAME_MAX);
        if (false === $firstName) {
            $errors['firstName'] = 'auth.register.error_first_name';
        }
        $lastName = TextInput::field($post, 'lastName', self::NAME_MAX);
        if (false === $lastName) {
            $errors['lastName'] = 'auth.register.error_last_name';
        }

        $password = $post['password'] ?? '';
        if (!\is_string($password) || !mb_check_encoding($password, 'UTF-8')) {
            $password = '';
        }
        if (\strlen($password) > self::PASSWORD_MAX_BYTES) {
            $errors['password'] = 'auth.register.error_password_too_long';
        } elseif (mb_strlen($password) < self::PASSWORD_MIN) {
            $errors['password'] = 'auth.register.error_password_too_short';
        } elseif (\is_string($email) && mb_strtolower(trim($password)) === mb_strtolower($email)) {
            $errors['password'] = 'auth.register.error_password_is_email';
        }

        return new self(
            \is_string($email) ? $email : '',
            \is_string($firstName) ? $firstName : null,
            \is_string($lastName) ? $lastName : null,
            $password,
            $errors,
        );
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * Valeurs à réafficher dans le formulaire (jamais le mot de passe) : la saisie telle quelle si c'est une chaîne
     * UTF-8, sinon vide.
     *
     * @param array<mixed> $post
     *
     * @return array{email: string, firstName: string, lastName: string}
     */
    public static function redisplay(array $post): array
    {
        $out = [];
        foreach (['email', 'firstName', 'lastName'] as $field) {
            $value       = TextInput::raw($post, $field);
            $out[$field] = mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, 500) : '';
        }

        return $out;
    }
}
