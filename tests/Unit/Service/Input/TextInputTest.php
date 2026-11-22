<?php

namespace App\Tests\Unit\Service\Input;

use App\Service\Input\TextInput;
use PHPUnit\Framework\TestCase;

/** Lecture des champs texte : UTF-8 exigé, longueur après normalisation, jamais de troncature. */
final class TextInputTest extends TestCase
{
    public function testNormalize(): void
    {
        $this->assertNull(TextInput::normalize(null, false));
        $this->assertNull(TextInput::normalize("  \t\r\n ", true));
        $this->assertSame('a b c', TextInput::normalize(" a\tb\n\nc ", false));
        $this->assertSame("a\n\nb c", TextInput::normalize("a\r\n\r\nb\tc", true));
        $this->assertSame('ab', TextInput::normalize("a\x00\x07b", true));
        $this->assertFalse(TextInput::normalize(['x'], false));
        $this->assertFalse(TextInput::normalize(42, false));
        $this->assertFalse(TextInput::normalize("caf\xE9", false), 'Latin-1 byte, not UTF-8');
    }

    public function testFieldLength(): void
    {
        $this->assertSame(str_repeat('é', 5), TextInput::field(['f' => str_repeat('é', 5)], 'f', 5));
        $this->assertFalse(TextInput::field(['f' => str_repeat('é', 6)], 'f', 5));
        // CRLF compte pour 1 (comme maxlength du navigateur)
        $this->assertSame("ab\ncd", TextInput::field(['f' => "ab\r\ncd"], 'f', 5, true));
        $this->assertNull(TextInput::field([], 'f', 5));
        $this->assertFalse(TextInput::field(['f' => ['a']], 'f', 5));
    }

    public function testRaw(): void
    {
        $this->assertSame(' 42 ', TextInput::raw(['f' => ' 42 '], 'f'));
        $this->assertSame('', TextInput::raw(['f' => ['42']], 'f'));
        $this->assertSame('', TextInput::raw([], 'f'));
    }
}
