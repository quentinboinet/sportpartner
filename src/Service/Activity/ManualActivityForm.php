<?php

namespace App\Service\Activity;

use App\Entity\Activity;
use App\Entity\Gear;
use App\Entity\GearType;
use App\Entity\Sport;
use App\Service\Health\FieldError;

/**
 * Formulaire d'une activité saisie à la main (création et modification) : valeurs saisies telles quelles
 * (réaffichées en cas d'erreur), validation (validate() : erreurs par champ) puis application à l'activité
 * (applyTo()). Calcul pur, rien n'est lu ni écrit en base ici.
 *
 * Règles :
 *  - sport : un slug de la table sports ;
 *  - nom : une ligne, NAME_MAX caractères au plus ; vide = nom par défaut « <Sport> du <date> » (contrôleur) ;
 *  - date (AAAA-MM-JJ, à partir de MIN_DATE) et heure de départ (HH:MM) locales, dans le fuseau du navigateur
 *    (champ timezone, rempli par le JS ; à défaut celui de l'activité modifiée ou celui de PHP). Le départ est
 *    stocké en UTC, comme les activités Strava et les séances Santé. Un départ dans le futur est REFUSÉ
 *    (tolérance FUTURE_TOLERANCE_SECONDS) : une activité est une séance faite, pas une séance prévue ;
 *  - temps en mouvement et temps écoulé saisis en trois champs entiers (heures 0-48, minutes 0-59, secondes 0-59 ;
 *    un champ vide vaut 0 ; pas de texte libre « 45:30 », ambigu entre 45 min 30 s et 45 h 30), total de 1 s à
 *    48 h ; temps en mouvement obligatoire (au moins un champ > 0), temps écoulé facultatif (trois champs vides =
 *    temps en mouvement, sinon au moins égal) ;
 *  - distance en km facultative, virgule ou point, 3 décimales au plus, de 0 exclu à 1000 km ; ignorée pour
 *    les sports sans distance (renfo) ;
 *  - D+ en mètres entiers de 0 à 20 000 ; ignoré pour la renfo et la natation ;
 *  - FC moyenne et max en bpm de 30 à 250, moyenne <= max ;
 *  - matériel : GEAR_AUTO (matériel par défaut du type lié au sport, comme la synchro Strava), GEAR_NONE, ou
 *    l'id d'un matériel proposé (non retiré, de l'utilisateur, plus celui déjà rattaché) d'un type compatible
 *    avec le sport (GearType::compatibleWithSportSlug()).
 */
final class ManualActivityForm
{
    public const NAME_MAX = 255;
    public const MAX_HOURS = 48;
    public const MAX_SECONDS = self::MAX_HOURS * 3600;
    public const MAX_DISTANCE_KM = 1000;
    public const MAX_ELEVATION_M = 20000;
    public const HR_MIN = 30;
    public const HR_MAX = 250;
    public const MIN_DATE = '2000-01-01';
    public const FUTURE_TOLERANCE_SECONDS = 300;
    public const GEAR_AUTO = 'auto';
    public const GEAR_NONE = '';
    public const DEFAULT_SPORT = 'run';
    /** Sports sans distance : champ masqué, valeur ignorée. */
    public const NO_DISTANCE_SPORTS = ['weight_training'];
    /** Sports sans D+ : champ masqué, valeur ignorée. */
    public const NO_ELEVATION_SPORTS = ['weight_training', 'swim'];

    public string $sport = self::DEFAULT_SPORT;
    public string $name = '';
    public string $date = '';
    public string $time = '';
    /** Temps en mouvement : heures, minutes, secondes saisies (vide = 0). */
    public string $movingH = '';
    public string $movingM = '';
    public string $movingS = '';
    /** Temps écoulé : trois champs vides = temps en mouvement. */
    public string $elapsedH = '';
    public string $elapsedM = '';
    public string $elapsedS = '';
    public string $distance = '';
    public string $elevation = '';
    public string $hrAvg = '';
    public string $hrMax = '';
    public string $gear = self::GEAR_AUTO;
    /** Fuseau IANA dans lequel la date et l'heure sont saisies. */
    public string $timezone = 'UTC';

    /** Valeurs lues par validate() (null tant que la saisie n'est pas valide). */
    private ?ManualActivityValues $values = null;

    /** Nouvelle saisie : date du jour (dans $timezone), sport par défaut, matériel par défaut. */
    public static function create(\DateTimeImmutable $now, string $timezone): self
    {
        $form = new self();
        $form->timezone = self::validTimezone($timezone) ?? date_default_timezone_get();
        $form->date = $now->setTimezone(new \DateTimeZone($form->timezone))->format('Y-m-d');

        return $form;
    }

    /**
     * @param array<string, mixed> $data             champs POST
     * @param string               $fallbackTimezone fuseau si le navigateur n'en a pas envoyé de valide
     */
    public static function fromRequest(array $data, string $fallbackTimezone): self
    {
        $string = static fn (string $key): string => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';

        $form = new self();
        $form->sport = $string('sport');
        $form->name = is_scalar($data['name'] ?? null) ? (string) $data['name'] : '';
        $form->date = $string('date');
        $form->time = $string('time');
        $form->movingH = $string('moving_h');
        $form->movingM = $string('moving_m');
        $form->movingS = $string('moving_s');
        $form->elapsedH = $string('elapsed_h');
        $form->elapsedM = $string('elapsed_m');
        $form->elapsedS = $string('elapsed_s');
        $form->distance = $string('distance');
        $form->elevation = $string('elevation');
        $form->hrAvg = $string('hr_avg');
        $form->hrMax = $string('hr_max');
        $form->gear = $string('gear');
        $form->timezone = self::validTimezone($string('timezone')) ?? self::validTimezone($fallbackTimezone) ?? date_default_timezone_get();

        return $form;
    }

    /** Saisie qui recharge l'activité (modification), dans son fuseau ; distance avec la virgule en français. */
    public static function fromActivity(Activity $activity, string $locale): self
    {
        $form = new self();
        $form->sport = $activity->getSport()?->getSlug() ?? 'other';
        $form->name = $activity->getName() ?? '';
        $form->timezone = $activity->getTimeZoneObject()->getName();
        $local = $activity->getLocalStartDate();
        $form->date = $local?->format('Y-m-d') ?? '';
        $form->time = $local?->format('H:i') ?? '';

        $moving = $activity->getMovingTimeSeconds();
        $elapsed = $activity->getElapsedTimeSeconds();
        if ($moving !== null && $moving > 0) {
            [$form->movingH, $form->movingM, $form->movingS] = self::splitSeconds($moving);
        }
        if ($elapsed !== null && $elapsed > 0 && $elapsed !== $moving) {
            [$form->elapsedH, $form->elapsedM, $form->elapsedS] = self::splitSeconds($elapsed);
        }

        $meters = $activity->getDistanceMeters();
        if ($meters !== null && $meters > 0) {
            $km = rtrim(rtrim(number_format($meters / 1000, 3, '.', ''), '0'), '.');
            $form->distance = str_starts_with($locale, 'fr') ? str_replace('.', ',', $km) : $km;
        }
        $elevation = $activity->getTotalElevationGain();
        $form->elevation = $elevation !== null ? number_format($elevation, 0, '.', '') : '';
        $hrAvg = $activity->getAverageHeartrate();
        $hrMax = $activity->getMaxHeartrate();
        $form->hrAvg = $hrAvg !== null ? number_format($hrAvg, 0, '.', '') : '';
        $form->hrMax = $hrMax !== null ? number_format($hrMax, 0, '.', '') : '';
        $form->gear = (string) ($activity->getGear()?->getId() ?? self::GEAR_NONE);

        return $form;
    }

    public static function hasDistance(string $sportSlug): bool
    {
        return !in_array($sportSlug, self::NO_DISTANCE_SPORTS, true);
    }

    public static function hasElevation(string $sportSlug): bool
    {
        return !in_array($sportSlug, self::NO_ELEVATION_SPORTS, true);
    }

    /**
     * @param list<string>          $sportSlugs sports existants
     * @param array<int, GearType>  $gearTypes  matériel proposé (id => type) : non retiré, plus celui déjà rattaché
     *
     * @return array<string, FieldError> par nom de champ ; vide si la saisie est valide
     */
    public function validate(array $sportSlugs, \DateTimeImmutable $now, array $gearTypes): array
    {
        $this->values = null;
        $errors = [];

        $sportOk = in_array($this->sport, $sportSlugs, true);
        if (!$sportOk) {
            $errors['sport'] = new FieldError('activity_form.error.sport');
        }

        $name = self::line($this->name);
        if (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = new FieldError('activity_form.error.name_too_long', ['%max%' => self::NAME_MAX]);
        }

        // Date et heure locales -> départ UTC
        $tz = new \DateTimeZone($this->timezone);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->date, $tz);
        $startUtc = null;
        if ($day === false || $day->format('Y-m-d') !== $this->date) {
            $errors['date'] = new FieldError('activity_form.error.date');
        } elseif ($this->date < self::MIN_DATE) {
            $errors['date'] = new FieldError('activity_form.error.date_min');
        }
        $clock = self::clock($this->time);
        if ($clock === null) {
            $errors['time'] = new FieldError('activity_form.error.time');
        }
        if ($day !== false && !isset($errors['date']) && $clock !== null) {
            $startUtc = $day->setTime($clock[0], $clock[1], $clock[2])->setTimezone(new \DateTimeZone('UTC'));
            if ($startUtc->getTimestamp() > $now->getTimestamp() + self::FUTURE_TOLERANCE_SECONDS) {
                // Sur l'heure si c'est aujourd'hui (dans le fuseau de la saisie), sinon sur la date
                $field = $this->date === $now->setTimezone($tz)->format('Y-m-d') ? 'time' : 'date';
                $errors[$field] = new FieldError('activity_form.error.future');
            }
        }

        // Durées
        $moving = self::duration([$this->movingH, $this->movingM, $this->movingS], 'moving', true, $errors);
        $elapsed = self::duration([$this->elapsedH, $this->elapsedM, $this->elapsedS], 'elapsed', false, $errors);
        if ($moving !== null && $elapsed !== null && $elapsed < $moving) {
            $errors['elapsed'] = new FieldError('activity_form.error.elapsed_lt_moving');
        }

        // Distance, D+, FC
        $distanceM = null;
        if ($sportOk && self::hasDistance($this->sport) && $this->distance !== '') {
            // Mètres entiers calculés sans flottant : « 21,097 » -> 21097
            $meters = 0;
            if (preg_match('/^(\d{1,4})(?:[.,](\d{1,3}))?$/', $this->distance, $m)) {
                $meters = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
            }
            if ($meters <= 0 || $meters > self::MAX_DISTANCE_KM * 1000) {
                $errors['distance'] = new FieldError('activity_form.error.distance', ['%max%' => self::MAX_DISTANCE_KM]);
            } else {
                $distanceM = (float) $meters;
            }
        }
        $elevation = null;
        if ($sportOk && self::hasElevation($this->sport) && $this->elevation !== '') {
            $elevation = self::intIn($this->elevation, 0, self::MAX_ELEVATION_M);
            if ($elevation === null) {
                $errors['elevation'] = new FieldError('activity_form.error.elevation', ['%max%' => self::MAX_ELEVATION_M]);
            }
        }
        $hrAvg = $this->heartrate($this->hrAvg, 'hr_avg', $errors);
        $hrMax = $this->heartrate($this->hrMax, 'hr_max', $errors);
        if ($hrAvg !== null && $hrMax !== null && $hrAvg > $hrMax) {
            $errors['hr_max'] = new FieldError('activity_form.error.hr_order');
        }

        // Matériel
        $gearId = null;
        if ($this->gear !== self::GEAR_AUTO && $this->gear !== self::GEAR_NONE) {
            $id = preg_match('/^\d{1,10}$/', $this->gear) ? (int) $this->gear : 0;
            $type = $gearTypes[$id] ?? null;
            if ($type === null || ($sportOk && !in_array($type, GearType::compatibleWithSportSlug($this->sport), true))) {
                $errors['gear'] = new FieldError('activity_form.error.gear');
            } else {
                $gearId = $id;
            }
        }

        if ($errors === [] && $startUtc !== null && $moving !== null) {
            $this->values = new ManualActivityValues(
                name: $name,
                startUtc: $startUtc,
                timezone: $this->timezone,
                movingSeconds: $moving,
                elapsedSeconds: $elapsed ?? $moving,
                distanceMeters: $distanceM,
                elevationMeters: $elevation,
                hrAvg: $hrAvg,
                hrMax: $hrMax,
                gearAuto: $this->gear === self::GEAR_AUTO,
                gearId: $gearId,
            );
        }

        return $errors;
    }

    /** Valeurs lues par le dernier validate() sans erreur. */
    public function values(): ManualActivityValues
    {
        return $this->values ?? throw new \LogicException('validate() must succeed before values() is called.');
    }

    /**
     * Recopie la saisie VALIDÉE dans l'activité. $gear est le matériel résolu par le contrôleur (choisi, par
     * défaut ou aucun) ; $defaultName sert quand le nom est vide. Les champs propres à Strava (tracé, flux,
     * vitesse moyenne, cadence) restent vides : allure et vitesse se calculent avec la distance et le temps.
     */
    public function applyTo(Activity $activity, Sport $sport, ?Gear $gear, string $defaultName): void
    {
        $v = $this->values();
        $activity
            ->setSport($sport)
            ->setName($v->name !== '' ? $v->name : mb_substr($defaultName, 0, self::NAME_MAX))
            ->setStartDate($v->startUtc)
            ->setTimezone($v->timezone)
            ->setMovingTimeSeconds($v->movingSeconds)
            ->setElapsedTimeSeconds($v->elapsedSeconds)
            ->setDistanceMeters($v->distanceMeters)
            ->setTotalElevationGain($v->elevationMeters !== null ? (float) $v->elevationMeters : null)
            ->setAverageHeartrate($v->hrAvg !== null ? (float) $v->hrAvg : null)
            ->setMaxHeartrate($v->hrMax !== null ? (float) $v->hrMax : null)
            ->setAverageSpeed(null)
            ->setAverageCadence(null)
            ->setGear($gear);
    }

    /** Texte sur une ligne : caractères de contrôle et retours à la ligne remplacés par des espaces, espaces simples. */
    public static function line(string $text): string
    {
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text));
    }

    /** Nom IANA connu, sinon null. */
    public static function validTimezone(string $name): ?string
    {
        return $name !== '' && strlen($name) <= 64 && in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? $name
            : null;
    }

    /**
     * Heure « HH:MM », « H:MM », « HH:MM:SS » ou « 7h05 ».
     *
     * @return array{int, int, int}|null [heures, minutes, secondes]
     */
    private static function clock(string $value): ?array
    {
        if (!preg_match('/^(\d{1,2})\s*[:hH]\s*([0-5]\d)(?::([0-5]\d))?$/', $value, $m) || (int) $m[1] > 23) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
    }

    /**
     * Durée saisie en trois champs [heures, minutes, secondes] : champ vide = 0, entiers seulement, heures de 0 à
     * MAX_HOURS, minutes et secondes de 0 à 59, total de 1 s à MAX_SECONDS. Trois champs vides : null (erreur si
     * $required). L'erreur porte sur le groupe ($field).
     *
     * @param array{string, string, string} $parts
     * @param array<string, FieldError>     $errors
     */
    private static function duration(array $parts, string $field, bool $required, array &$errors): ?int
    {
        if ($parts === ['', '', '']) {
            if ($required) {
                $errors[$field] = new FieldError('activity_form.error.duration_required');
            }

            return null;
        }
        $seconds = 0;
        foreach ([[$parts[0], self::MAX_HOURS, 3600], [$parts[1], 59, 60], [$parts[2], 59, 1]] as [$value, $max, $unit]) {
            if ($value === '') {
                continue;
            }
            if (!preg_match('/^\d{1,2}$/', $value) || (int) $value > $max) {
                $errors[$field] = new FieldError('activity_form.error.duration_parts', ['%max%' => self::MAX_HOURS]);

                return null;
            }
            $seconds += (int) $value * $unit;
        }
        if ($seconds <= 0 || $seconds > self::MAX_SECONDS) {
            $errors[$field] = new FieldError('activity_form.error.duration_range', ['%max%' => self::MAX_HOURS]);

            return null;
        }

        return $seconds;
    }

    /**
     * Secondes -> [heures, minutes, secondes] pour recharger les trois champs.
     *
     * @return array{string, string, string}
     */
    private static function splitSeconds(int $seconds): array
    {
        return [(string) intdiv($seconds, 3600), (string) intdiv($seconds % 3600, 60), (string) ($seconds % 60)];
    }

    /** @param array<string, FieldError> $errors */
    private function heartrate(string $value, string $field, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }
        $bpm = self::intIn($value, self::HR_MIN, self::HR_MAX);
        if ($bpm === null) {
            $errors[$field] = new FieldError('activity_form.error.hr', ['%min%' => self::HR_MIN, '%max%' => self::HR_MAX]);
        }

        return $bpm;
    }

    /** Entier saisi (chiffres seulement) dans [$min, $max], sinon null. */
    private static function intIn(string $value, int $min, int $max): ?int
    {
        if (!preg_match('/^\d{1,6}$/', $value)) {
            return null;
        }
        $int = (int) $value;

        return $int >= $min && $int <= $max ? $int : null;
    }
}
