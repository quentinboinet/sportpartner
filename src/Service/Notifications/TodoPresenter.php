<?php

namespace App\Service\Notifications;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Met les éléments « À faire » au format JSON de app_notifications : textes traduits dans la langue de la
 * requête, nombres mis en forme (« 12,5 » / « 12.5 », « 1 000 » / « 1,000 »), lien relatif.
 *
 * Les textes sont du TEXTE BRUT : un nom saisi par l'utilisateur (course, matériel, activité) y est recopié
 * tel quel. Le navigateur doit les insérer avec textContent, jamais comme du HTML.
 */
final class TodoPresenter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<TodoItem> $items
     *
     * @return list<array{key: string, severity: string, icon: string, title: string, subtitle: ?string, url: string}>
     */
    public function present(array $items, string $locale): array
    {
        return array_map(fn (TodoItem $item): array => [
            'key'      => $item->key,
            'severity' => $item->severity,
            'icon'     => $item->icon,
            'title'    => $this->text($item->title, $locale),
            'subtitle' => $item->subtitle !== null ? $this->text($item->subtitle, $locale) : null,
            'url'      => $this->urls->generate($item->route, $item->routeParams).($item->fragment !== null ? '#'.$item->fragment : ''),
        ], $items);
    }

    public function text(TodoText $text, string $locale): string
    {
        $params = [];
        foreach ($text->params as $name => $value) {
            $params[$name] = match (true) {
                $value instanceof TodoText => $this->text($value, $locale),
                is_array($value)           => $this->join($value, $locale),
                is_int($value), is_float($value) => self::number($value, $locale),
                default                    => $value,
            };
        }

        $out = $this->translator->trans($text->key, $params, 'messages', $locale);
        // Sport inconnu de la table de référence (pas de traduction sport.<slug>) : le slug lui-même
        if ($out === $text->key && str_starts_with($text->key, 'sport.')) {
            $out = substr($text->key, 6);
        }

        return $text->lowercase ? mb_strtolower($out) : $out;
    }

    /** @param list<TodoText> $parts « a », « a et b », « a, b et c » */
    private function join(array $parts, string $locale): string
    {
        $texts = array_map(fn (TodoText $part): string => $this->text($part, $locale), $parts);
        $last = array_pop($texts);
        if ($last === null) {
            return '';
        }
        if ($texts === []) {
            return $last;
        }

        return implode($this->translator->trans('notifications.list_separator', [], 'messages', $locale), $texts)
            .$this->translator->trans('notifications.list_last', [], 'messages', $locale)
            .$last;
    }

    /**
     * Entier avec séparateur de milliers ; décimal au dixième (rien après la virgule s'il est entier).
     * L'arrondi au dixième est fait en entiers (demi vers le haut), sans round() ni number_format() décimal :
     * même résultat en PHP 8.3 et 8.4+.
     */
    public static function number(int|float $value, string $locale): string
    {
        $fr = !str_starts_with($locale, 'en');
        $thousands = $fr ? ' ' : ',';
        if (is_int($value)) {
            return number_format($value, 0, '', $thousands);
        }

        $tenths = (int) floor(abs($value) * 10 + 0.5);
        $sign = $value < 0 && $tenths > 0 ? '-' : '';
        $whole = number_format(intdiv($tenths, 10), 0, '', $thousands);

        return $sign.$whole.($tenths % 10 !== 0 ? ($fr ? ',' : '.').($tenths % 10) : '');
    }
}
