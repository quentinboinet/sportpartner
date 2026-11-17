<?php

namespace App\Service\Activity;

/**
 * Règles d'écriture CSV selon la langue de l'utilisateur :
 *  - français : séparateur « ; » et virgule décimale (Excel en français ouvre le fichier directement) ;
 *  - autres langues : séparateur « , » et point décimal ;
 *  - dans tous les cas : UTF-8 avec BOM (sinon Excel lit les accents en Windows-1252), fins de ligne CRLF,
 *    guillemets doubles autour des cellules qui contiennent le séparateur, un guillemet, un espace ou un retour
 *    à la ligne, guillemets internes doublés (RFC 4180 : fputcsv sans caractère d'échappement).
 *
 * Injection de formules (OWASP « CSV Injection ») : toute cellule qui commence par =, +, -, @, une tabulation ou un
 * retour chariot est précédée d'une apostrophe, qui la fait lire comme du texte.
 */
final class CsvFormat
{
    public const BOM = "\xEF\xBB\xBF";
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        public readonly string $separator,
        public readonly string $decimalPoint,
    ) {
    }

    public static function forLocale(string $locale): self
    {
        return str_starts_with(strtolower($locale), 'fr') ? new self(';', ',') : new self(',', '.');
    }

    /** Cellule texte neutralisée contre l'injection de formules. */
    public static function escapeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true) ? "'".$value : $value;
    }

    /** Nombre à $decimals décimales, sans séparateur de milliers. */
    public function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, $this->decimalPoint, '');
    }

    /**
     * Écrit une ligne (cellules neutralisées, puis guillemets si besoin).
     *
     * @param resource     $handle
     * @param list<string> $cells
     */
    public function writeRow($handle, array $cells): void
    {
        fputcsv($handle, array_map(self::escapeCell(...), $cells), $this->separator, '"', '', "\r\n");
    }
}
