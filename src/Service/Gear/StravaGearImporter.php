<?php

namespace App\Service\Gear;

use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearBrandRepository;
use App\Repository\GearRepository;
use App\Service\Strava\StravaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importe le matériel Strava de l'utilisateur (identifiants "g..." = chaussures, "b..." = vélos).
 *
 * 1. Lecture (aucune écriture) : toutes les activités Strava, 200 par page, pour savoir quel
 *    matériel a servi à quelle activité ; GET /athlete (au mieux : avec l'autorisation
 *    profile:read_all il liste aussi le matériel jamais utilisé) ; puis un seul GET /gear/{id}
 *    par matériel. 401/403, 429 ou erreur réseau : on s'arrête, rien n'est enregistré.
 * 2. Matériel créé ou mis à jour (clé : utilisateur + identifiant Strava ; un matériel saisi à la
 *    main de même type, marque et modèle est repris plutôt que dupliqué s'il est le seul candidat).
 *    Strava fait foi pour le modèle (sinon le nom) ; la marque "Autre" et l'absence de surnom
 *    n'écrasent pas une saisie locale ; durée de vie, date d'achat et notes restent locales.
 *    Retiré sur Strava => mis à la retraite ; une retraite locale n'est jamais annulée.
 *    Matériel "primary" de Strava => matériel par défaut, seulement si l'utilisateur n'a pas déjà
 *    un matériel par défaut en service de ce type.
 * 3. Activités déjà importées : celles dont l'activité Strava indique un matériel reçoivent le
 *    matériel importé si elles n'en ont pas (ou toujours, avec $replaceLinks). Les activités Strava
 *    sans matériel ne sont pas modifiées.
 * 4. Kilométrage initial = max(0, total Strava - distance des activités rattachées), recalculé à
 *    chaque import : le total affiché dans l'application égale celui de Strava.
 */
class StravaGearImporter
{
    /** Activités par page (maximum accepté par Strava). */
    public const PAGE_SIZE = 200;
    /** Garde-fou : 50 pages (10 000 activités), sous le quota Strava de 100 lectures par 15 min. */
    public const MAX_PAGES = 50;

    private const TIMEOUT_S      = 15;
    private const MAX_DURATION_S = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StravaService $strava,
        private readonly EntityManagerInterface $em,
        private readonly GearRepository $gearRepo,
        private readonly GearBrandRepository $brandRepo,
        private readonly ActivityRepository $activityRepo,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function import(User $user, bool $replaceLinks = false): StravaGearImportResult
    {
        if ($user->getStravaAccessToken() === null || $user->getStravaAccessToken() === '') {
            return StravaGearImportResult::failed(StravaGearImportResult::ERROR_NOT_CONNECTED);
        }

        // 1. Lecture complète avant toute écriture
        try {
            $this->ensureFreshToken($user);
            [$activityGear, $lastActivityId, $truncated] = $this->fetchActivityGear($user);
            [$profileGearIds, $profileScopeMissing]     = $this->fetchProfileGearIds($user);

            $gearIds = array_values(array_unique([...array_values(array_filter($activityGear, 'is_string')), ...$profileGearIds]));
            $details = [];
            $skipped = 0;
            foreach ($gearIds as $gearId) {
                $data = self::typeFromId($gearId) !== null ? $this->fetchGear($user, $gearId) : null;
                if ($data === null) {
                    $skipped++;
                    continue;
                }
                $details[$gearId] = $data;
            }
        } catch (StravaGearImportException $e) {
            $this->logger->warning('Strava gear import aborted: {error}', ['error' => $e->error, 'exception' => $e->getPrevious()]);

            return StravaGearImportResult::failed($e->error);
        }

        // 2 à 4. Écriture en une transaction
        return $this->em->wrapInTransaction(fn (): StravaGearImportResult => $this->apply(
            $user,
            $details,
            $activityGear,
            $lastActivityId,
            $replaceLinks,
            new StravaGearImportResult(skipped: $skipped, truncated: $truncated, profileScopeMissing: $profileScopeMissing),
        ));
    }

    /** Type de matériel d'après l'identifiant Strava ("b..." vélo, "g..." chaussures), null si inconnu. */
    public static function typeFromId(string $stravaGearId): ?GearType
    {
        if (preg_match('/^([bg])[0-9]{1,30}$/', $stravaGearId, $m) !== 1) {
            return null;
        }

        return $m[1] === 'b' ? GearType::Bike : GearType::Shoes;
    }

    /**
     * @param array<string, array{name: string, nickname: string, brand: string, model: string, distance: float, primary: bool, retired: bool}> $details
     * @param array<int, ?string> $activityGear Activité Strava => identifiant du matériel (null : aucun).
     */
    private function apply(
        User $user,
        array $details,
        array $activityGear,
        ?int $lastActivityId,
        bool $replaceLinks,
        StravaGearImportResult $partial,
    ): StravaGearImportResult {
        $brands  = $this->brandRepo->findAllOrdered();
        $gears   = $this->gearRepo->findByUser($user);
        $skipped = $partial->skipped;

        $byStravaId = [];
        foreach ($gears as $gear) {
            if ($gear->getStravaGearId() !== null) {
                $byStravaId[$gear->getStravaGearId()] = $gear;
            }
        }

        // Matériel : création ou mise à jour
        $imported  = [];
        $primaries = [];
        $created   = 0;
        $updated   = 0;
        foreach ($details as $stravaGearId => $data) {
            $type  = self::typeFromId($stravaGearId) ?? GearType::Shoes;
            $brand = GearBrandMatcher::match($data['brand'], $type, $brands);
            if ($brand === null) {
                $skipped++; // aucune marque "Autre" en base : impossible de créer le matériel
                continue;
            }
            $model    = self::model($data, $stravaGearId);
            $nickname = self::nickname($data, $model, $brand);

            $gear = $byStravaId[$stravaGearId] ?? self::findManualTwin($gears, $type, $brand, $model);
            if ($gear === null) {
                $gear = (new Gear())
                    ->setUser($user)
                    ->setType($type)
                    ->setBrand($brand)
                    ->setModel($model)
                    ->setNickname($nickname)
                    ->setMaxDistanceM($type->defaultMaxDistanceM());
                $this->em->persist($gear);
                $gears[] = $gear;
                $created++;
            } else {
                $gear->setModel($model);
                if (!$brand->isOther()) {
                    $gear->setBrand($brand);
                }
                if ($nickname !== null) {
                    $gear->setNickname($nickname);
                }
                $updated++;
            }

            $gear->setStravaGearId($stravaGearId)->setStravaLastActivityId($lastActivityId);
            if ($data['retired'] && !$gear->isRetired()) {
                $gear->setRetiredAt(new \DateTimeImmutable('today'))->setIsDefault(false);
            }
            if ($data['primary']) {
                $primaries[$type->value] ??= $gear;
            }
            $imported[$stravaGearId] = $gear;
        }

        self::applyStravaDefaults($primaries, $gears);

        // Identifiants des nouveaux matériels, nécessaires au rattachement
        $this->em->flush();

        // Rattachement des activités déjà importées
        $gearById = [];
        foreach ($gears as $gear) {
            if ($gear->getId() !== null) {
                $gearById[$gear->getId()] = $gear;
            }
        }
        $toAssign = [];
        $linkedM  = [];
        $linked   = 0;
        foreach ($this->activityRepo->findGearLinkRows($user) as $row) {
            $current = $row['gearId'] !== null ? ($gearById[$row['gearId']] ?? null) : null;
            $target  = $row['stravaId'] !== null && isset($activityGear[$row['stravaId']])
                ? ($imported[$activityGear[$row['stravaId']]] ?? null)
                : null;

            $final = $current;
            if ($target !== null && $target !== $current && ($row['gearId'] === null || $replaceLinks)) {
                $toAssign[(int) $target->getId()][] = $row['id'];
                $final = $target;
                $linked++;
            }
            if ($final !== null) {
                $linkedM[spl_object_id($final)] = ($linkedM[spl_object_id($final)] ?? 0.0) + $row['distanceM'];
            }
        }
        foreach ($toAssign as $gearId => $activityIds) {
            $this->activityRepo->assignGear($user, $gearId, $activityIds);
        }

        // Kilométrage initial : ce que Strava compte en plus des activités rattachées ici
        foreach ($imported as $stravaGearId => $gear) {
            $rest = $details[$stravaGearId]['distance'] - ($linkedM[spl_object_id($gear)] ?? 0.0);
            $gear->setInitialDistanceM(max(0, (int) round($rest)));
        }

        return new StravaGearImportResult(
            created: $created,
            updated: $updated,
            linked: $linked,
            skipped: $skipped,
            truncated: $partial->truncated,
            profileScopeMissing: $partial->profileScopeMissing,
        );
    }

    /**
     * Le matériel "primary" de Strava devient le matériel par défaut de son type, sauf si
     * l'utilisateur a déjà un matériel par défaut en service de ce type (ou si celui-ci est retiré).
     *
     * @param array<string, Gear> $primaries Matériel principal Strava, par type.
     * @param list<Gear>          $gears     Tout le matériel de l'utilisateur.
     */
    private static function applyStravaDefaults(array $primaries, array $gears): void
    {
        foreach ($primaries as $primary) {
            if ($primary->isRetired() || $primary->isDefault()) {
                continue;
            }
            $sameType = array_filter($gears, static fn (Gear $g): bool => $g !== $primary && $g->getType() === $primary->getType());
            foreach ($sameType as $other) {
                if ($other->isDefault() && !$other->isRetired()) {
                    continue 2;
                }
            }
            foreach ($sameType as $other) {
                $other->setIsDefault(false);
            }
            $primary->setIsDefault(true);
        }
    }

    /**
     * Matériel saisi à la main (sans identifiant Strava) de même type, marque et modèle : repris
     * plutôt que dupliqué, s'il est le seul candidat.
     *
     * @param list<Gear> $gears
     */
    private static function findManualTwin(array $gears, GearType $type, GearBrand $brand, string $model): ?Gear
    {
        $key        = GearBrandMatcher::simplify($model);
        $candidates = array_values(array_filter($gears, static fn (Gear $g): bool => $g->getStravaGearId() === null
            && $g->getType() === $type
            && ($g->getBrand() === $brand || ($brand->getId() !== null && $g->getBrand()->getId() === $brand->getId()))
            && GearBrandMatcher::simplify($g->getModel()) === $key));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** @param array{name: string, nickname: string, brand: string, model: string, distance: float, primary: bool, retired: bool} $data */
    private static function model(array $data, string $stravaGearId): string
    {
        $model = $data['model'] !== '' ? $data['model'] : ($data['name'] !== '' ? $data['name'] : $stravaGearId);

        return mb_substr($model, 0, 120);
    }

    /**
     * Surnom : le nom donné sur Strava s'il diffère du modèle (et de "marque modèle").
     *
     * @param array{name: string, nickname: string, brand: string, model: string, distance: float, primary: bool, retired: bool} $data
     */
    private static function nickname(array $data, string $model, GearBrand $brand): ?string
    {
        $name = $data['nickname'] !== '' ? $data['nickname'] : $data['name'];
        if ($name === '') {
            return null;
        }
        $key = GearBrandMatcher::simplify($name);
        foreach ([$model, $data['brand'].' '.$model, $brand->getName().' '.$model] as $same) {
            if ($key === GearBrandMatcher::simplify($same)) {
                return null;
            }
        }

        return mb_substr($name, 0, 60);
    }

    // ── Strava ─────────────────────────────────────────────────────────────────

    /** @throws StravaGearImportException */
    private function ensureFreshToken(User $user): void
    {
        if (!$this->strava->isTokenExpired($user)) {
            return;
        }
        try {
            $this->strava->refreshAccessToken($user);
        } catch (HttpExceptionInterface $e) {
            $status = $e->getResponse()->getStatusCode();
            throw new StravaGearImportException(match (true) {
                $status === 429                  => StravaGearImportResult::ERROR_RATE_LIMIT,
                $status >= 400 && $status < 500 => StravaGearImportResult::ERROR_AUTH, // jeton de rafraîchissement révoqué
                default                          => StravaGearImportResult::ERROR_UNAVAILABLE,
            }, $e);
        } catch (\Throwable $e) {
            throw new StravaGearImportException(StravaGearImportResult::ERROR_UNAVAILABLE, $e);
        }
    }

    /**
     * Toutes les activités Strava (les plus récentes d'abord), 200 par page jusqu'à une page incomplète.
     *
     * @return array{0: array<int, ?string>, 1: ?int, 2: bool} [activité Strava => matériel, id d'activité le plus élevé, liste tronquée]
     *
     * @throws StravaGearImportException
     */
    private function fetchActivityGear(User $user): array
    {
        $map  = [];
        $max  = null;
        $page = 1;
        do {
            $items = $this->get($user, '/athlete/activities', ['per_page' => self::PAGE_SIZE, 'page' => $page]);
            if ($items === null || !array_is_list($items)) {
                throw new StravaGearImportException(StravaGearImportResult::ERROR_UNAVAILABLE);
            }
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) {
                    continue;
                }
                $id       = (int) $item['id'];
                $gearId   = $item['gear_id'] ?? null;
                $map[$id] = is_string($gearId) && $gearId !== '' ? $gearId : null;
                $max      = max($max ?? $id, $id);
            }
            $full = count($items) === self::PAGE_SIZE;
            $page++;
        } while ($full && $page <= self::MAX_PAGES);

        return [$map, $max, $full];
    }

    /**
     * Vélos et chaussures du profil (GET /athlete), au mieux : ils ne sont présents qu'avec
     * l'autorisation profile:read_all. Seul un 429 interrompt l'import.
     *
     * @return array{0: list<string>, 1: bool} [identifiants, autorisation profile:read_all absente]
     *
     * @throws StravaGearImportException
     */
    private function fetchProfileGearIds(User $user): array
    {
        try {
            $athlete = $this->get($user, '/athlete');
        } catch (StravaGearImportException $e) {
            if ($e->error === StravaGearImportResult::ERROR_RATE_LIMIT) {
                throw $e;
            }

            return [[], false];
        }
        if ($athlete === null) {
            return [[], false];
        }
        if (!array_key_exists('bikes', $athlete) && !array_key_exists('shoes', $athlete)) {
            return [[], true];
        }

        $ids = [];
        foreach (['bikes', 'shoes'] as $key) {
            foreach (is_array($athlete[$key] ?? null) ? $athlete[$key] : [] as $gear) {
                if (is_array($gear) && is_string($gear['id'] ?? null) && $gear['id'] !== '') {
                    $ids[] = $gear['id'];
                }
            }
        }

        return [$ids, false];
    }

    /**
     * Détail d'un matériel (GET /gear/{id}) ; null s'il est introuvable (404) ou illisible.
     *
     * @return array{name: string, nickname: string, brand: string, model: string, distance: float, primary: bool, retired: bool}|null
     *
     * @throws StravaGearImportException
     */
    private function fetchGear(User $user, string $stravaGearId): ?array
    {
        $data = $this->get($user, '/gear/'.rawurlencode($stravaGearId));
        if ($data === null || array_is_list($data)) {
            return null;
        }
        $text = static fn (mixed $v): string => is_string($v) ? trim($v) : '';

        return [
            'name'     => $text($data['name'] ?? null),
            'nickname' => $text($data['nickname'] ?? null),
            'brand'    => $text($data['brand_name'] ?? null),
            'model'    => $text($data['model_name'] ?? null),
            'distance' => is_numeric($data['distance'] ?? null) ? max(0.0, (float) $data['distance']) : 0.0,
            'primary'  => ($data['primary'] ?? false) === true,
            'retired'  => ($data['retired'] ?? false) === true,
        ];
    }

    /**
     * GET sur l'API Strava avec le jeton de l'utilisateur ; null si 404.
     *
     * @param array<string, int|string> $query
     *
     * @return array<mixed>|null
     *
     * @throws StravaGearImportException
     */
    private function get(User $user, string $path, array $query = []): ?array
    {
        try {
            $response = $this->httpClient->request('GET', StravaService::API_BASE.$path, [
                'headers'      => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()],
                'query'        => $query,
                'timeout'      => self::TIMEOUT_S,
                'max_duration' => self::MAX_DURATION_S,
            ]);
            $status = $response->getStatusCode();
            if ($status === 404) {
                return null;
            }
            if ($status !== 200) {
                $this->logger->warning('Strava API returned HTTP {status} for {path}', ['status' => $status, 'path' => $path]);
                throw new StravaGearImportException(match ($status) {
                    401, 403 => StravaGearImportResult::ERROR_AUTH,
                    429      => StravaGearImportResult::ERROR_RATE_LIMIT,
                    default  => StravaGearImportResult::ERROR_UNAVAILABLE,
                });
            }

            return $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw new StravaGearImportException(StravaGearImportResult::ERROR_UNAVAILABLE, $e);
        }
    }
}
