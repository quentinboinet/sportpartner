<?php

namespace App\Service\Route;

/**
 * Lit un fichier GPX (1.0 / 1.1, avec ou sans namespace) et produit un tracé ré-échantillonné prêt à stocker.
 *
 * Sécurité, dans l'ordre :
 * - taille bornée (MAX_BYTES) ;
 * - encodage compatible ASCII seulement (UTF-8, Latin-1...) : pas d'octet nul (UTF-16 / UTF-32), premier caractère
 *   significatif « < », encodage déclaré dans la liste blanche ASCII_COMPATIBLE. Sans cela, les contrôles qui suivent
 *   (recherche d'octets) ne verraient pas un « <!DOCTYPE » écrit en UTF-16 ;
 * - DOCTYPE / ENTITY refusés avant le parsing (XXE, « billion laughs »), et encore vérifié après ;
 * - nombre de balises borné (MAX_TAGS, en comptant les « < ») AVANT de construire le DOM : libxml alloue l'arbre hors
 *   de la memory_limit de PHP, un fichier plein d'éléments vides (« <a/> ») occuperait sinon des centaines de Mo ;
 * - LIBXML_NONET, jamais LIBXML_NOENT ni LIBXML_DTDLOAD ;
 * - nombre de points bruts borné (MAX_RAW_POINTS), compté par XPath dans l'arbre libxml déjà construit, avant de
 *   créer les tableaux PHP des points.
 *
 * Ré-échantillonnage : on garde des points GPS réels (pas d'interpolation, le tracé carte reste exact) :
 * premier, dernier, puis un point dès qu'on a parcouru au moins max(10 m, distance/5000) depuis le dernier
 * gardé. Pour ne pas raboter un sommet ou un creux, dans chaque intervalle sauté on garde en plus le point
 * qui s'écarte le plus (> 1 m) de la corde d'altitude entre les deux points gardés.
 */
final class GpxParser
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    /** ~55 h d'enregistrement à 1 point/s : largement au-delà d'un ultra réel. */
    public const MAX_RAW_POINTS = 200_000;
    /**
     * Balises (ouvrantes, fermantes, vides, commentaires, instructions : chaque « < ») au plus. Un point Garmin
     * complet (<trkpt>, <ele>, <time>, extensions FC / cadence) compte 10 à 14 « < » : 200 000 points font environ
     * 2,4 millions. Un fichier réel de 15 Mo reste bien en dessous (environ 1 « < » pour 16 octets au plus serré).
     */
    public const MAX_TAGS = 2_500_000;
    /** Encodages déclarés acceptés (<?xml encoding="..."?>), tous compatibles ASCII ; absent = UTF-8. */
    private const ASCII_COMPATIBLE = '/^(utf-?8|us-ascii|ascii|iso-8859-\d{1,2}|latin-?1|windows-125\d|cp125\d)$/i';
    private const EARTH_RADIUS_M = 6371008.8;
    private const MIN_STEP_M = 10.0;
    private const MAX_STORED_POINTS = 5000;
    private const EXTREMUM_KEEP_M = 1.0;
    private const NAME_MAX = 200;

    public function __construct(
        private readonly RouteAnalyzer $analyzer = new RouteAnalyzer(),
    ) {
    }

    public function parse(string $xml): ParsedRoute
    {
        $doc = $this->load($xml);
        $root = $doc->documentElement;
        if (null === $root || 'gpx' !== $root->localName) {
            throw new InvalidGpxException('Root element is not <gpx>.');
        }

        [$lat, $lon, $ele] = $this->readPoints($doc);
        $n = \count($lat);
        if ($n < 2) {
            throw new InvalidGpxException('GPX must contain at least 2 valid track or route points.');
        }

        $cum = [0.0];
        for ($i = 1; $i < $n; ++$i) {
            $cum[] = $cum[$i - 1] + $this->haversine($lat[$i - 1], $lon[$i - 1], $lat[$i], $lon[$i]);
        }
        $total = $cum[$n - 1];
        if ($total < 1.0) {
            throw new InvalidGpxException('GPX track has zero length.');
        }

        $ele = $this->fillElevation($ele, $cum);

        $points = [];
        foreach ($this->resample($cum, $ele, $total) as $i) {
            $points[] = [
                (int) round($cum[$i]),
                null === $ele[$i] ? null : round($ele[$i], 1),
                round($lat[$i], 6),
                round($lon[$i], 6),
            ];
        }

        $distanceM = $points[\count($points) - 1][0];
        $gl = $this->analyzer->gainLoss($points, 0, $distanceM);
        $eles = array_values(array_filter(array_column($points, 1), static fn (?float $e): bool => null !== $e));

        return new ParsedRoute(
            points: $points,
            distanceM: $distanceM,
            gainM: $gl['gain'],
            lossM: $gl['loss'],
            minEle: [] === $eles ? null : min($eles),
            maxEle: [] === $eles ? null : max($eles),
            name: $this->readName($doc),
        );
    }

    private function load(string $xml): \DOMDocument
    {
        if (\strlen($xml) > self::MAX_BYTES) {
            throw new InvalidGpxException('GPX file is too large.', InvalidGpxException::TOO_LARGE);
        }
        if ('' === trim($xml)) {
            throw new InvalidGpxException('GPX file is empty.');
        }
        // UTF-16 / UTF-32 (octets nuls), EBCDIC... : les recherches d'octets ci-dessous seraient aveugles.
        if (str_contains($xml, "\0") || !str_starts_with(ltrim($xml, "\xEF\xBB\xBF \t\r\n"), '<')) {
            throw new InvalidGpxException('GPX must use an ASCII-compatible encoding such as UTF-8.');
        }
        if (1 === preg_match('/^(?:\xEF\xBB\xBF)?\s*<\?xml[^>]*?\sencoding\s*=\s*["\']([^"\']*)["\']/', $xml, $m)
            && 1 !== preg_match(self::ASCII_COMPATIBLE, $m[1])) {
            throw new InvalidGpxException('GPX must use an ASCII-compatible encoding such as UTF-8.');
        }
        // Pas de DTD : bloque XXE et « billion laughs » avant même le parsing.
        if (1 === preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
            throw new InvalidGpxException('GPX with DOCTYPE or ENTITY declarations is not allowed.');
        }
        // Bombe d'éléments : refusée avant que libxml ne construise l'arbre (substr_count : rapide, sans allocation).
        if (substr_count($xml, '<') > self::MAX_TAGS) {
            throw new InvalidGpxException('GPX has too many elements.', InvalidGpxException::TOO_LARGE);
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($xml, \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok || null !== $doc->doctype) {
            throw new InvalidGpxException('GPX is not well-formed XML.');
        }

        return $doc;
    }

    /**
     * Points de trace (<trk>/<trkseg>/<trkpt>, dans l'ordre) ou, à défaut, de route (<rte>/<rtept>).
     *
     * @return array{list<float>, list<float>, list<?float>}
     */
    private function readPoints(\DOMDocument $doc): array
    {
        // Comptage côté libxml (XPath count) : rien n'est alloué en PHP avant le contrôle du plafond.
        $xpath = new \DOMXPath($doc);
        $tag = 'trkpt';
        $count = (int) $xpath->evaluate("count(//*[local-name()='trkpt'])");
        if (0 === $count) {
            $tag = 'rtept';
            $count = (int) $xpath->evaluate("count(//*[local-name()='rtept'])");
        }
        if ($count > self::MAX_RAW_POINTS) {
            throw new InvalidGpxException('GPX has too many points.', InvalidGpxException::TOO_LARGE);
        }

        $lat = [];
        $lon = [];
        $ele = [];
        foreach ($this->walk($doc, $tag) as $el) {
            $la = $this->toFloat($el->getAttribute('lat'));
            $lo = $this->toFloat($el->getAttribute('lon'));
            // (0,0) = absence de fix sur certains appareils.
            if (null === $la || null === $lo || abs($la) > 90 || abs($lo) > 180 || (0.0 === $la && 0.0 === $lo)) {
                continue;
            }
            $eleEl = $this->child($el, 'ele');
            $e = null === $eleEl ? null : $this->toFloat($eleEl->textContent);
            $lat[] = $la;
            $lon[] = $lo;
            $ele[] = null !== $e && $e >= -1000 && $e <= 10000 ? $e : null;
        }

        return [$lat, $lon, $ele];
    }

    /**
     * Parcours en profondeur itératif, dans l'ordre du document, sans descendre dans les éléments trouvés.
     * Un seul nœud PHP vivant à la fois (DOMXPath::query ou une liste matérialiserait tous les points).
     *
     * @return \Generator<int, \DOMElement>
     */
    private function walk(\DOMDocument $doc, string $localName): \Generator
    {
        $root = $doc->documentElement;
        $node = $root?->firstChild;
        while (null !== $node) {
            if ($node instanceof \DOMElement) {
                if ($node->localName === $localName) {
                    yield $node;
                } elseif (null !== $node->firstChild) {
                    $node = $node->firstChild;
                    continue;
                }
            }
            while (null === $node->nextSibling) {
                $node = $node->parentNode;
                if (null === $node || $node->isSameNode($root)) {
                    return;
                }
            }
            $node = $node->nextSibling;
        }
    }

    /**
     * Comble les altitudes manquantes : interpolation linéaire (en distance) entre voisins connus,
     * prolongement de la valeur connue la plus proche aux extrémités. Aucune altitude → tout null.
     *
     * @param list<?float> $ele
     * @param list<float>  $cum
     *
     * @return list<?float>
     */
    private function fillElevation(array $ele, array $cum): array
    {
        $known = array_keys(array_filter($ele, static fn (?float $e): bool => null !== $e));
        if ([] === $known) {
            return $ele;
        }
        // Un seul passage (linéaire) : chaque trou est comblé entre ses deux bornes connues.
        $first = $known[0];
        $last = $known[\count($known) - 1];
        for ($i = 0; $i < $first; ++$i) {
            $ele[$i] = $ele[$first];
        }
        for ($i = $last + 1, $n = \count($ele); $i < $n; ++$i) {
            $ele[$i] = $ele[$last];
        }
        for ($k = 1, $nk = \count($known); $k < $nk; ++$k) {
            $a = $known[$k - 1];
            $b = $known[$k];
            if ($b - $a < 2) {
                continue;
            }
            $ea = (float) $ele[$a];
            $eb = (float) $ele[$b];
            $span = $cum[$b] - $cum[$a];
            for ($i = $a + 1; $i < $b; ++$i) {
                $ele[$i] = $span > 0 ? $ea + ($eb - $ea) * ($cum[$i] - $cum[$a]) / $span : $ea;
            }
        }

        return $ele;
    }

    /**
     * Indices des points conservés (voir doc de classe).
     *
     * @param list<float>  $cum
     * @param list<?float> $ele
     *
     * @return list<int>
     */
    private function resample(array $cum, array $ele, float $total): array
    {
        $step = max(self::MIN_STEP_M, $total / self::MAX_STORED_POINTS);
        $n = \count($cum);
        $kept = [0];
        $lastKept = 0;
        for ($i = 1; $i < $n; ++$i) {
            if ($i < $n - 1 && $cum[$i] - $cum[$lastKept] < $step) {
                continue;
            }
            $extremum = $this->extremumBetween($cum, $ele, $lastKept, $i);
            if (null !== $extremum) {
                $kept[] = $extremum;
            }
            $kept[] = $i;
            $lastKept = $i;
        }

        return $kept;
    }

    /**
     * Point intermédiaire le plus éloigné (> seuil) de la corde d'altitude entre $a et $b, sinon null.
     *
     * @param list<float>  $cum
     * @param list<?float> $ele
     */
    private function extremumBetween(array $cum, array $ele, int $a, int $b): ?int
    {
        if ($b - $a < 2 || null === $ele[$a] || null === $ele[$b]) {
            return null;
        }
        $span = $cum[$b] - $cum[$a];
        $best = null;
        $bestDev = self::EXTREMUM_KEEP_M;
        for ($j = $a + 1; $j < $b; ++$j) {
            $chord = $span > 0 ? $ele[$a] + ($ele[$b] - $ele[$a]) * ($cum[$j] - $cum[$a]) / $span : $ele[$a];
            $dev = abs((float) $ele[$j] - $chord);
            if ($dev > $bestDev) {
                $bestDev = $dev;
                $best = $j;
            }
        }

        return $best;
    }

    private function readName(\DOMDocument $doc): ?string
    {
        $root = $doc->documentElement;
        if (null === $root) {
            return null;
        }
        $metadata = $this->child($root, 'metadata');
        $candidates = [
            null === $metadata ? null : $this->child($metadata, 'name'), // GPX 1.1
            $this->child($root, 'name'),                                  // GPX 1.0
        ];
        // Puis le nom de la première <trk>, à défaut celui de la première <rte>.
        $firstOf = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            if (('trk' === $el->localName || 'rte' === $el->localName) && !isset($firstOf[$el->localName])) {
                $firstOf[$el->localName] = $this->child($el, 'name');
            }
        }
        $candidates[] = $firstOf['trk'] ?? null;
        $candidates[] = $firstOf['rte'] ?? null;
        foreach ($candidates as $el) {
            $name = null === $el ? '' : trim((string) preg_replace('/\s+/u', ' ', $el->textContent));
            if ('' !== $name) {
                return mb_substr($name, 0, self::NAME_MAX);
            }
        }

        return null;
    }

    private function child(\DOMElement $parent, string $localName): ?\DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $localName) {
                return $node;
            }
        }

        return null;
    }

    private function toFloat(string $value): ?float
    {
        $value = trim($value);
        if (!is_numeric($value)) {
            return null;
        }
        $f = (float) $value;

        return is_finite($f) ? $f : null;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
