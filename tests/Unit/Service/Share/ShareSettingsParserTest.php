<?php

namespace App\Tests\Unit\Service\Share;

use App\Entity\RaceShare;
use App\Service\Share\ShareSettingsParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validation des réglages du partage : longueurs (après normalisation des fins de ligne), options, scénario,
 * messages des repères.
 */
final class ShareSettingsParserTest extends TestCase
{
    private const CPS = [101, 102, 103];
    private const SCENARIOS = [11, 12];

    /**
     * @param array<string, mixed> $post
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    private static function parse(array $post): array
    {
        return ShareSettingsParser::parse($post, self::CPS, self::SCENARIOS);
    }

    #[Test]
    public function validFormIsNormalized(): void
    {
        $r = self::parse([
            'display_name' => "  Camille\n  et\tLéa  ",
            'scenario' => '12',
            'show_paces' => '1',
            'show_cutoffs' => 'on',
            'message' => "  Merci !\r\n\r\nÀ tout à l'heure\x07  ",
            'start_message' => '',
            'finish_message' => '   ',
            'messages' => ['101' => "Maillot rouge\r\ncôté gauche", '103' => '', '999' => 'autre course'],
        ]);

        self::assertSame([], $r['errors']);
        self::assertSame([
            'displayName' => 'Camille et Léa',
            'scenarioId' => 12,
            'showPaces' => true,
            'showCutoffs' => true,
            'message' => "Merci !\n\nÀ tout à l'heure",
            'startMessage' => null,
            'finishMessage' => null,
            'checkpointMessages' => [101 => "Maillot rouge\ncôté gauche", 103 => null],
        ], $r['values']);
    }

    #[Test]
    public function absentFieldsGiveDefaultsAndUntouchedCheckpoints(): void
    {
        $r = self::parse([]);

        self::assertSame([], $r['errors']);
        self::assertNull($r['values']['displayName']);
        self::assertNull($r['values']['scenarioId']);
        self::assertFalse($r['values']['showPaces'], 'unchecked checkbox');
        self::assertFalse($r['values']['showCutoffs']);
        self::assertSame([], $r['values']['checkpointMessages'], 'checkpoints absent from the POST are not modified');
    }

    #[Test]
    public function lengthsAreCheckedAfterLineEndingNormalization(): void
    {
        $max = RaceShare::MAX_POINT_MESSAGE_LENGTH;
        // 498 caractères + un saut de ligne CRLF + 1 : 500 caractères une fois normalisé (maxlength du navigateur)
        $ok = str_repeat('é', 498)."\r\n".'x';
        $r = self::parse(['start_message' => $ok, 'messages' => ['102' => str_repeat('a', $max)]]);
        self::assertSame([], $r['errors']);
        self::assertSame($max, mb_strlen((string) $r['values']['startMessage']));

        $r = self::parse([
            'display_name' => str_repeat('n', RaceShare::MAX_DISPLAY_NAME_LENGTH + 1),
            'message' => str_repeat('m', RaceShare::MAX_MESSAGE_LENGTH + 1),
            'start_message' => str_repeat('s', $max + 1),
            'finish_message' => str_repeat('f', $max + 1),
            'messages' => ['101' => str_repeat('c', $max + 1), '102' => str_repeat('c', $max)],
        ]);
        self::assertSame([
            'display_name' => 'too_long',
            'message' => 'too_long',
            'start_message' => 'too_long',
            'finish_message' => 'too_long',
            'messages.101' => 'too_long',
        ], $r['errors']);
        // La saisie est gardée telle quelle pour être réaffichée
        self::assertSame($max + 1, mb_strlen((string) $r['values']['checkpointMessages'][101]));

        $r = self::parse(['message' => str_repeat('m', RaceShare::MAX_MESSAGE_LENGTH), 'display_name' => str_repeat('n', RaceShare::MAX_DISPLAY_NAME_LENGTH)]);
        self::assertSame([], $r['errors']);
    }

    /** @return iterable<string, array{mixed, bool|null}> valeur => attendu (null = invalide) */
    public static function flags(): iterable
    {
        yield 'checked 1' => ['1', true];
        yield 'checked on' => ['on', true];
        yield 'zero' => ['0', false];
        yield 'empty' => ['', false];
        yield 'yes' => ['yes', null];
        yield 'true' => ['true', null];
        yield 'array' => [['1'], null];
    }

    #[Test]
    #[DataProvider('flags')]
    public function optionParsing(mixed $value, ?bool $expected): void
    {
        $r = self::parse(['show_paces' => $value, 'show_cutoffs' => $value]);
        if (null === $expected) {
            self::assertSame(['show_paces' => 'invalid', 'show_cutoffs' => 'invalid'], $r['errors']);

            return;
        }
        self::assertSame([], $r['errors']);
        self::assertSame($expected, $r['values']['showPaces']);
        self::assertSame($expected, $r['values']['showCutoffs']);
    }

    #[Test]
    public function scenarioMustBelongToTheRace(): void
    {
        self::assertSame(11, self::parse(['scenario' => '11'])['values']['scenarioId']);
        self::assertNull(self::parse(['scenario' => ''])['values']['scenarioId']);
        foreach (['13', 'abc', '11.0', '-11', ' 1 1'] as $bad) {
            self::assertSame(['scenario' => 'invalid'], self::parse(['scenario' => $bad])['errors'], $bad);
        }
        self::assertSame(['scenario' => 'invalid'], self::parse(['scenario' => ['11']])['errors']);
    }

    #[Test]
    public function nonStringOrInvalidUtf8IsInvalid(): void
    {
        self::assertSame(['message' => 'invalid'], self::parse(['message' => ['x']])['errors']);
        self::assertSame(['display_name' => 'invalid'], self::parse(['display_name' => "\xC3\x28"])['errors']);
        self::assertSame(['messages.102' => 'invalid'], self::parse(['messages' => ['102' => ['x']]])['errors']);
        self::assertSame(['messages' => 'invalid'], self::parse(['messages' => 'texte'])['errors']);
    }

    #[Test]
    public function userTextIsKeptVerbatimForEscapingAtOutput(): void
    {
        $payload = '<img src=x onerror=alert(1)> "><script>alert(2)</script>';
        $r = self::parse(['message' => $payload, 'display_name' => $payload, 'messages' => ['101' => $payload]]);

        self::assertSame([], $r['errors']);
        self::assertSame($payload, $r['values']['message']);
        self::assertSame($payload, $r['values']['displayName']);
        self::assertSame($payload, $r['values']['checkpointMessages'][101]);
    }
}
