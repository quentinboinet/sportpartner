<?php

namespace App\Tests\Unit\Service\User;

use App\Controller\Auth\RegisterController;
use App\Service\User\RegistrationForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Règles du formulaire d'inscription, appliquées avant tout hachage du mot de passe. */
final class RegistrationFormTest extends TestCase
{
    /** @return array<string, string> */
    private static function valid(): array
    {
        return ['email' => 'jeanne@example.com', 'firstName' => 'Jeanne', 'lastName' => 'Martin', 'password' => 'correct horse'];
    }

    public function testValidInputIsNormalized(): void
    {
        $form = RegistrationForm::fromPost(['email' => '  jeanne@example.com ', 'firstName' => " Jeanne\t ", 'lastName' => '', 'password' => '  spaces kept  ']);

        $this->assertTrue($form->isValid(), json_encode($form->errors) ?: '');
        $this->assertSame('jeanne@example.com', $form->email);
        $this->assertSame('Jeanne', $form->firstName);
        $this->assertNull($form->lastName);
        $this->assertSame('  spaces kept  ', $form->password, 'password never trimmed');
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'no email' => [['email' => '  '], 'email', 'auth.register.error_email_required'];
        yield 'email missing' => [['email' => null], 'email', 'auth.register.error_email_required'];
        yield 'email as array' => [['email' => ['a@b.c']], 'email', 'auth.register.error_email_too_long'];
        yield 'email not UTF-8' => [['email' => "a\xFF@example.com"], 'email', 'auth.register.error_email_too_long'];
        yield 'email 181 chars' => [['email' => str_repeat('a', 169).'@example.com'], 'email', 'auth.register.error_email_too_long'];
        yield 'email without domain dot' => [['email' => 'jeanne@localhost'], 'email', 'auth.register.error_email_invalid'];
        yield 'email with space' => [['email' => 'jeanne martin@example.com'], 'email', 'auth.register.error_email_invalid'];
        yield 'email without @' => [['email' => 'jeanne.example.com'], 'email', 'auth.register.error_email_invalid'];
        yield 'first name 101 chars' => [['firstName' => str_repeat('é', 101)], 'firstName', 'auth.register.error_first_name'];
        yield 'first name not UTF-8' => [['firstName' => "Je\xC3anne"], 'firstName', 'auth.register.error_first_name'];
        yield 'last name as array' => [['lastName' => ['x']], 'lastName', 'auth.register.error_last_name'];
        yield 'password 9 chars' => [['password' => '123456789'], 'password', 'auth.register.error_password_too_short'];
        yield 'password empty' => [['password' => ''], 'password', 'auth.register.error_password_too_short'];
        yield 'password as array' => [['password' => ['correct horse']], 'password', 'auth.register.error_password_too_short'];
        yield 'password not UTF-8' => [['password' => "correct\xFFhorse"], 'password', 'auth.register.error_password_too_short'];
        yield 'password 4097 bytes' => [['password' => str_repeat('a', 4097)], 'password', 'auth.register.error_password_too_long'];
        yield 'password = email' => [['password' => 'jeanne@example.com'], 'password', 'auth.register.error_password_is_email'];
        yield 'password = email, other case' => [['password' => ' Jeanne@Example.COM '], 'password', 'auth.register.error_password_is_email'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreRefused(array $override, string $field, string $error): void
    {
        $form = RegistrationForm::fromPost($override + self::valid());

        $this->assertFalse($form->isValid());
        $this->assertSame([$field => $error], $form->errors);
    }

    public function testLimitsAreInclusive(): void
    {
        $this->assertTrue(RegistrationForm::fromPost(['email' => str_repeat('a', 168).'@example.com'] + self::valid())->isValid(), '180 chars');
        $this->assertTrue(RegistrationForm::fromPost(['firstName' => str_repeat('é', 100)] + self::valid())->isValid());
        $this->assertTrue(RegistrationForm::fromPost(['password' => '1234567890'] + self::valid())->isValid(), '10 chars');
        $this->assertTrue(RegistrationForm::fromPost(['password' => 'éééééééééé'] + self::valid())->isValid(), '10 characters, 20 bytes');
        $this->assertTrue(RegistrationForm::fromPost(['password' => str_repeat('a', 4096)] + self::valid())->isValid());
    }

    public function testSeveralErrorsAtOnce(): void
    {
        $form = RegistrationForm::fromPost(['email' => 'x', 'password' => 'short']);
        $this->assertSame(['email' => 'auth.register.error_email_invalid', 'password' => 'auth.register.error_password_too_short'], $form->errors);
    }

    public function testRedisplayNeverIncludesThePasswordNorInvalidBytes(): void
    {
        $values = RegistrationForm::redisplay(['email' => "a\xFF@b.c", 'firstName' => ['x'], 'lastName' => 'Martin', 'password' => 'secret-secret']);
        $this->assertSame(['email' => '', 'firstName' => '', 'lastName' => 'Martin'], $values);
    }

    public function testLimitsMatchTheUserColumnsAndTheTemplate(): void
    {
        $this->assertSame(180, RegistrationForm::EMAIL_MAX);
        $this->assertSame(100, RegistrationForm::NAME_MAX);
        $this->assertSame(10, RegistrationForm::PASSWORD_MIN);
        $this->assertSame(4096, RegistrationForm::PASSWORD_MAX_BYTES);
        $this->assertSame('register', RegisterController::CSRF_ID);

        $twig = (string) file_get_contents(__DIR__.'/../../../../templates/auth/register.html.twig');
        $this->assertStringContainsString("minlength=\"{{ constant('App\\\\Service\\\\User\\\\RegistrationForm::PASSWORD_MIN') }}\"", $twig);
        $this->assertStringContainsString('name="_csrf_token"', $twig);
        $this->assertStringNotContainsString('minlength="8"', $twig);
    }

    public function testErrorKeysExistInBothLanguages(): void
    {
        $keys = ['error_csrf', 'error_rate_limited', 'error_email_required', 'error_email_too_long', 'error_email_invalid', 'error_email_taken',
            'error_first_name', 'error_last_name', 'error_password_too_short', 'error_password_too_long', 'error_password_is_email', 'flash_created', 'password_help'];
        foreach (['fr', 'en'] as $locale) {
            $register = Yaml::parseFile(__DIR__.'/../../../../translations/messages.'.$locale.'.yaml')['auth']['register'];
            foreach ($keys as $key) {
                $this->assertIsString($register[$key] ?? null, "$locale auth.register.$key");
            }
            $this->assertStringContainsString('%minutes%', $register['error_rate_limited']);
            $this->assertStringContainsString('%min%', $register['password_help']);
        }
    }
}
