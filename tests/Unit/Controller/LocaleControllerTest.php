<?php

namespace App\Tests\Unit\Controller;

use App\Controller\LocaleController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Choix de la langue : retour seulement vers un chemin du même site (pas de redirection ouverte). */
final class LocaleControllerTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function referers(): iterable
    {
        yield 'same host path' => ['http://localhost:8080/courses/12', '/courses/12'];
        yield 'same host with query' => ['https://localhost/stats?period=month&sport=run', '/stats?period=month&sport=run'];
        yield 'same host, fragment dropped' => ['https://localhost/courses/1/plan#scenario-3', '/courses/1/plan'];
        yield 'same host, no path' => ['https://localhost', '/'];
        yield 'host case' => ['https://LOCALHOST/login', '/login'];
        yield 'other host' => ['https://evil.example/phish', null];
        yield 'subdomain trick' => ['https://localhost.evil.example/', null];
        yield 'userinfo trick' => ['https://localhost@evil.example/', null];
        yield 'protocol-relative path' => ['https://localhost//evil.example/x', null];
        yield 'backslash path' => ['https://localhost/\\evil.example/x', null];
        yield 'relative referer' => ['/courses', null];
        yield 'empty' => ['', null];
        yield 'garbage' => ['http:///', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'locale switch itself' => ['https://localhost/switch-locale/en', null];
        yield 'control char in query' => ["https://localhost/a?x=\r\nSet-Cookie:a", null];
    }

    #[DataProvider('referers')]
    public function testSameHostTarget(string $referer, ?string $expected): void
    {
        $this->assertSame($expected, LocaleController::sameHostTarget($referer, 'localhost'));
    }
}
