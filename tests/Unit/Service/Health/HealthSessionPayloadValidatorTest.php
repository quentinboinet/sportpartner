<?php

namespace App\Tests\Unit\Service\Health;

use App\Service\Health\HealthSessionPayloadValidator;
use App\Service\Health\InvalidHealthPayloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contrat JSON de l'enregistrement d'une séance guidée (POST app_health_session_save). */
final class HealthSessionPayloadValidatorTest extends TestCase
{
    private const NOW = '2026-11-19 18:00:00';

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function payload(array $override = []): array
    {
        return array_merge([
            'name' => null, 'startedAt' => self::now()->getTimestamp() - 1800, 'timezone' => 'Europe/Paris',
            'durationSeconds' => 1500, 'elapsedSeconds' => 1620, 'rpe' => 6, 'notes' => null, 'addActivity' => true, 'sport' => null,
            'exercises' => [
                ['id' => 7, 'sets' => 3, 'value' => 12, 'rest' => 45, 'performed' => [12, 12, 10]],
                ['id' => 27, 'sets' => 2, 'value' => 30, 'rest' => 30, 'performed' => []],
            ],
        ], $override);
    }

    private static function validate(mixed $payload): \App\Service\Health\HealthSessionPayload
    {
        return (new HealthSessionPayloadValidator())->validate($payload, self::now());
    }

    public function testValidPayload(): void
    {
        $p = self::validate(self::payload());

        self::assertNull($p->name);
        self::assertSame('2026-11-19 17:30:00', $p->startedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Paris', $p->timezone);
        self::assertSame([1500, 1620, 6, null, true, null], [$p->durationSeconds, $p->elapsedSeconds, $p->rpe, $p->notes, $p->addActivity, $p->sport]);
        self::assertCount(2, $p->exercises);
        self::assertSame([7, 3, 12, 45, [12, 12, 10]], [$p->exercises[0]->exerciseId, $p->exercises[0]->sets, $p->exercises[0]->value, $p->exercises[0]->rest, $p->exercises[0]->performed]);
        self::assertSame([], $p->exercises[1]->performed);
    }

    public function testTextFieldsAreNormalized(): void
    {
        $p = self::validate(self::payload(['name' => "  Renfo \n du   mardi ", 'notes' => " Ligne 1\r\n\r\n\r\n\r\nLigne 2\x07 ", 'rpe' => null, 'sport' => 'other']));
        self::assertSame('Renfo du mardi', $p->name);
        self::assertSame("Ligne 1\n\nLigne 2", $p->notes);
        self::assertNull($p->rpe);
        self::assertSame('other', $p->sport);
        // Nom vide ou blanc -> nom par défaut
        self::assertNull(self::validate(self::payload(['name' => '   ']))->name);
    }

    public function testUnknownTimezoneIsIgnoredNotRefused(): void
    {
        self::assertNull(self::validate(self::payload(['timezone' => 'Mars/Olympus']))->timezone);
        self::assertNull(self::validate(self::payload(['timezone' => null]))->timezone);
        self::assertSame('UTC', self::validate(self::payload(['timezone' => 'UTC']))->timezone);
    }

    public function testBoundaries(): void
    {
        $now = self::now()->getTimestamp();
        self::validate(self::payload(['startedAt' => $now - 172800]));
        self::validate(self::payload(['startedAt' => $now + 300]));
        self::validate(self::payload(['durationSeconds' => 21600, 'elapsedSeconds' => 21600]));
        self::validate(self::payload(['notes' => str_repeat('é', 1000), 'name' => str_repeat('a', 80), 'rpe' => 10]));
        self::validate(self::payload(['exercises' => array_fill(0, 30, ['id' => 1, 'sets' => 10, 'value' => 600, 'rest' => 600, 'performed' => [600]])]));
        $this->addToAssertionCount(5);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidProvider(): iterable
    {
        $now = (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->getTimestamp();
        $ex = ['id' => 7, 'sets' => 3, 'value' => 12, 'rest' => 45, 'performed' => [12]];

        yield 'pas un objet'            => ['nope', 'invalid_payload'];
        yield 'liste'                   => [[1, 2], 'invalid_payload'];
        yield 'clé inconnue'            => [['hack' => 1], 'invalid_payload'];
        yield 'nom pas une chaîne'      => [['name' => 12], 'invalid_payload'];
        yield 'nom trop long'           => [['name' => str_repeat('a', 81)], 'invalid_payload'];
        yield 'début en chaîne'         => [['startedAt' => '1763571600'], 'invalid_payload'];
        yield 'début trop ancien'       => [['startedAt' => $now - 172801], 'invalid_start'];
        yield 'début dans le futur'     => [['startedAt' => $now + 301], 'invalid_start'];
        yield 'fuseau pas une chaîne'   => [['timezone' => 1], 'invalid_payload'];
        yield 'durée nulle'             => [['durationSeconds' => 0], 'invalid_payload'];
        yield 'durée > 6 h'             => [['durationSeconds' => 21601, 'elapsedSeconds' => 30000], 'invalid_payload'];
        yield 'durée flottante'         => [['durationSeconds' => 12.5], 'invalid_payload'];
        yield 'écoulé < durée'          => [['elapsedSeconds' => 1499], 'invalid_payload'];
        yield 'écoulé > 24 h'           => [['elapsedSeconds' => 86401], 'invalid_payload'];
        yield 'rpe 0'                   => [['rpe' => 0], 'invalid_payload'];
        yield 'rpe 11'                  => [['rpe' => 11], 'invalid_payload'];
        yield 'rpe en chaîne'           => [['rpe' => '6'], 'invalid_payload'];
        yield 'notes trop longues'      => [['notes' => str_repeat('a', 1001)], 'invalid_payload'];
        yield 'notes en tableau'        => [['notes' => ['a']], 'invalid_payload'];
        yield 'addActivity manquant'    => [['addActivity' => null], 'invalid_payload'];
        yield 'addActivity en chaîne'   => [['addActivity' => 'true'], 'invalid_payload'];
        yield 'sport inconnu'           => [['sport' => 'run'], 'invalid_payload'];
        yield 'aucun exercice'          => [['exercises' => []], 'invalid_payload'];
        yield 'exercices en objet'      => [['exercises' => ['a' => $ex]], 'invalid_payload'];
        yield '31 exercices'            => [['exercises' => array_fill(0, 31, $ex)], 'invalid_payload'];
        yield 'exercice en liste'       => [['exercises' => [[1, 2]]], 'invalid_payload'];
        yield 'clé d\'exercice inconnue' => [['exercises' => [$ex + ['name' => 'x']]], 'invalid_payload'];
        yield 'id 0'                    => [['exercises' => [['id' => 0] + $ex]], 'invalid_payload'];
        yield 'séries 11'               => [['exercises' => [['sets' => 11] + $ex]], 'invalid_payload'];
        yield 'valeur 601'              => [['exercises' => [['value' => 601] + $ex]], 'invalid_payload'];
        yield 'repos -1'                => [['exercises' => [['rest' => -1] + $ex]], 'invalid_payload'];
        yield 'trop de séries faites'   => [['exercises' => [['sets' => 1, 'performed' => [12, 12]] + $ex]], 'invalid_payload'];
        yield 'série faite à 0'         => [['exercises' => [['performed' => [0]] + $ex]], 'invalid_payload'];
        yield 'série faite > prévu'     => [['exercises' => [['performed' => [13]] + $ex]], 'invalid_payload'];
        yield 'série faite en chaîne'   => [['exercises' => [['performed' => ['12']] + $ex]], 'invalid_payload'];
        yield 'rien de fait'            => [['exercises' => [['performed' => []] + $ex, ['performed' => []] + $ex]], 'nothing_done'];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidPayloads(mixed $override, string $code): void
    {
        $payload = is_array($override) && !array_is_list($override) && !array_key_exists('hack', $override) ? self::payload($override) : $override;
        if (is_array($override) && array_key_exists('hack', $override)) {
            $payload = self::payload() + $override;
        }
        try {
            self::validate($payload);
            self::fail('accepted');
        } catch (InvalidHealthPayloadException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }
}
