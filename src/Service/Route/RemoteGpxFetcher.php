<?php

namespace App\Service\Route;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge un GPX depuis un lien fourni par l'utilisateur.
 *
 * Sécurité (SSRF) : toutes les requêtes passent par NoPrivateNetworkHttpClient, qui refuse
 * les adresses de BLOCKED_SUBNETS (privées, locales, link-local, CGNAT, documentation,
 * multicast, réservées ; IPv4 et IPv6), y compris après résolution DNS et à chaque redirection. Seuls http et https sont acceptés, sans identifiants
 * dans l'URL. Taille plafonnée pendant le téléchargement (pas seulement via Content-Length).
 */
final class RemoteGpxFetcher
{
    public const MAX_URL_LENGTH = 2048;
    private const TIMEOUT_S = 10;
    private const MAX_DURATION_S = 30;
    private const MAX_REDIRECTS = 3;

    /**
     * Plages refusées. Avec une liste explicite, NoPrivateNetworkHttpClient n'ajoute plus le
     * contrôle intégré de PHP (FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE) : la liste doit être complète.
     */
    public const BLOCKED_SUBNETS = [
        ...IpUtils::PRIVATE_SUBNETS,  // loopback, RFC1918, link-local, 0/8, 240/4, ULA, IPv4-mapped, 6to4, Teredo, NAT64
        '100.64.0.0/10',      // RFC6598 CGNAT (Tailscale, métadonnées Alibaba Cloud 100.100.100.200)
        '192.0.0.0/24',       // RFC6890 IETF protocol assignments
        '192.0.2.0/24',       // RFC5737 TEST-NET-1
        '192.88.99.0/24',     // RFC7526 relais 6to4 (obsolète)
        '198.18.0.0/15',      // RFC2544 benchmarking
        '198.51.100.0/24',    // RFC5737 TEST-NET-2
        '203.0.113.0/24',     // RFC5737 TEST-NET-3
        '224.0.0.0/4',        // multicast
        '255.255.255.255/32', // broadcast
        '100::/64',           // RFC6666 discard-only
        '2001:db8::/32',      // RFC3849 documentation
        '3fff::/20',          // RFC9637 documentation
        'fec0::/10',          // site-local (obsolète)
        'ff00::/8',           // multicast
    ];

    private readonly HttpClientInterface $client;

    public function __construct(HttpClientInterface $httpClient, private readonly int $maxBytes = GpxParser::MAX_BYTES)
    {
        $this->client = new NoPrivateNetworkHttpClient($httpClient, self::BLOCKED_SUBNETS);
    }

    /**
     * Valide l'URL et convertit les liens de partage courants en liens de téléchargement direct.
     *
     * @throws RemoteGpxException INVALID_URL
     */
    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            throw new RemoteGpxException('Invalid URL.', RemoteGpxException::INVALID_URL);
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RemoteGpxException('Invalid URL.', RemoteGpxException::INVALID_URL);
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        if ($host === 'www.dropbox.com' || $host === 'dropbox.com') {
            // Dropbox : ?dl=0 (aperçu) -> ?dl=1 (fichier brut)
            parse_str($parts['query'] ?? '', $query);
            $query['dl'] = '1';
            $url = 'https://www.dropbox.com'.$path.'?'.http_build_query($query);
        } elseif ($host === 'drive.google.com' && preg_match('#^/file/d/([A-Za-z0-9_-]+)#', $path, $m)) {
            // Google Drive : /file/d/{id}/view -> téléchargement direct
            $url = 'https://drive.google.com/uc?export=download&id='.$m[1];
        }

        // La réécriture ré-encode la requête (jusqu'à ~3x plus longue) et l'URL finale est
        // stockée dans RaceRoute.sourceUrl (VARCHAR 2048) : on revérifie donc sa longueur.
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new RemoteGpxException('Invalid URL.', RemoteGpxException::INVALID_URL);
        }

        return $url;
    }

    /**
     * @throws RemoteGpxException
     */
    public function fetch(string $url): RemoteGpx
    {
        $url = $this->normalizeUrl($url);

        try {
            $response = $this->client->request('GET', $url, [
                'timeout'       => self::TIMEOUT_S,
                'max_duration'  => self::MAX_DURATION_S,
                'max_redirects' => self::MAX_REDIRECTS,
                'buffer'        => false,
                'headers'       => [
                    'User-Agent' => 'SportPartner-GPX-Import/1.0',
                    'Accept'     => 'application/gpx+xml, application/xml, text/xml;q=0.9, */*;q=0.5',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                $response->cancel();
                throw new RemoteGpxException(sprintf('HTTP %d.', $status), RemoteGpxException::HTTP_ERROR);
            }

            $headers = $response->getHeaders(false);
            $declared = (int) ($headers['content-length'][0] ?? 0);
            if ($declared > $this->maxBytes) {
                $response->cancel();
                throw new RemoteGpxException('Too large.', RemoteGpxException::TOO_LARGE);
            }

            // Lecture en flux avec plafond : un serveur peut mentir sur Content-Length ou l'omettre
            $content = '';
            foreach ($this->client->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (strlen($content) > $this->maxBytes) {
                    $response->cancel();
                    throw new RemoteGpxException('Too large.', RemoteGpxException::TOO_LARGE);
                }
            }

            $finalUrl = (string) ($response->getInfo('url') ?: $url);
            $disposition = $headers['content-disposition'][0] ?? '';
        } catch (RemoteGpxException $e) {
            throw $e;
        } catch (TransportException $e) {
            $blocked = self::isBlockedAddress($e->getMessage());
            throw new RemoteGpxException($e->getMessage(), $blocked ? RemoteGpxException::BLOCKED : RemoteGpxException::UNREACHABLE, $e);
        } catch (ExceptionInterface $e) {
            throw new RemoteGpxException($e->getMessage(), RemoteGpxException::UNREACHABLE, $e);
        }

        if (self::looksLikeHtml($content)) {
            throw new RemoteGpxException('HTML page, not a GPX file.', RemoteGpxException::NOT_GPX);
        }

        return new RemoteGpx($content, self::fileName($finalUrl, $disposition), $url);
    }

    /**
     * NoPrivateNetworkHttpClient refuse avec 'Host "x" is blocked for "url".' (ou 'IP "x" ...'),
     * y compris quand le nom d'hôte ne se résout pas du tout (faute de frappe, domaine inexistant) :
     * ce dernier cas est un site injoignable, pas une adresse interne.
     */
    private static function isBlockedAddress(string $message): bool
    {
        if (!preg_match('/^(Host|IP) "([^"]*)" is blocked for /', $message, $m)) {
            return false;
        }
        $host = trim($m[2], '[]');
        if ($m[1] === 'IP' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return @gethostbynamel($host) !== false || !empty(@dns_get_record($host, DNS_AAAA));
    }

    private static function looksLikeHtml(string $content): bool
    {
        $head = strtolower(ltrim(substr($content, 0, 1024), "\xEF\xBB\xBF \t\r\n"));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html')
            || (!str_contains($head, '<gpx') && preg_match('/<(head|body|script|meta)\b/', $head) === 1);
    }

    /** Nom de fichier : Content-Disposition, sinon dernier segment de l'URL finale, sinon "parcours.gpx". */
    private static function fileName(string $finalUrl, string $disposition): string
    {
        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $disposition, $m)) {
            $name = rawurldecode(trim($m[1], " \"'"));
        } elseif (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            $name = trim($m[1]);
        } else {
            $name = rawurldecode(basename((string) parse_url($finalUrl, PHP_URL_PATH)));
        }

        return preg_match('/\.(gpx|xml)$/i', $name) ? $name : 'parcours.gpx';
    }
}
