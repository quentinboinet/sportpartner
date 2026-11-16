<?php

namespace App\Service\Health;

use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthEquipment;
use App\Entity\HealthExercise;
use App\Entity\HealthExerciseText;
use App\Entity\HealthLevel;

/**
 * Formulaire d'un exercice personnel (création et modification) : valeurs saisies telles quelles (réaffichées en
 * cas d'erreur), validation (validate() : erreurs par champ) puis application à l'entité (applyTo()).
 *
 * Mêmes champs que les exercices fournis, mêmes longueurs maximales (HealthExerciseText::*_MAX). Étapes et erreurs
 * fréquentes : une par ligne (puces « - », « * » ou numéros « 1. » en début de ligne retirés). Les textes sont
 * stockés dans une seule langue, celle de l'interface à la création, et affichés tels quels.
 */
final class HealthExerciseForm
{
    public string $name = '';
    public string $category = '';
    public string $level = '';
    /** @var list<string> */
    public array $areas = [];
    /** @var list<string> */
    public array $equipment = [];
    public bool $unilateral = false;
    public string $muscles = '';
    public string $start = '';
    public string $steps = '';
    public string $breathing = '';
    public string $mistakes = '';
    public string $easier = '';
    public string $harder = '';
    public string $mode = 'reps';
    public string $sets = '3';
    public string $value = '10';
    public string $rest = '45';
    public string $videoUrl = '';

    /** @param array<string, mixed> $data champs POST */
    public static function fromRequest(array $data): self
    {
        $form = new self();
        $string = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $list = static function (string $key) use ($data): array {
            $raw = $data[$key] ?? [];
            if (!is_array($raw)) {
                return [];
            }

            return array_values(array_unique(array_map('strval', array_filter($raw, 'is_scalar'))));
        };

        $form->name = $string('name');
        $form->category = $string('category');
        $form->level = $string('level');
        $form->areas = $list('areas');
        $form->equipment = $list('equipment');
        $form->unilateral = in_array($string('unilateral'), ['1', 'on', 'true'], true);
        $form->muscles = $string('muscles');
        $form->start = $string('start');
        $form->steps = $string('steps');
        $form->breathing = $string('breathing');
        $form->mistakes = $string('mistakes');
        $form->easier = $string('easier');
        $form->harder = $string('harder');
        $form->mode = $string('mode');
        $form->sets = $string('sets');
        $form->value = $string('value');
        $form->rest = $string('rest');
        $form->videoUrl = $string('video_url');

        return $form;
    }

    public static function fromExercise(HealthExercise $exercise, string $locale): self
    {
        $form = new self();
        $text = $exercise->textFor($locale);
        $form->name = $text?->getName() ?? '';
        $form->category = $exercise->getCategory()->value;
        $form->level = $exercise->getLevel()->value;
        $form->areas = array_map(static fn (HealthBodyArea $a): string => $a->value, $exercise->getBodyAreas());
        $form->equipment = array_map(static fn (HealthEquipment $e): string => $e->value, $exercise->getEquipment());
        $form->unilateral = $exercise->isUnilateral();
        $form->muscles = $text?->getTargetMuscles() ?? '';
        $form->start = $text?->getStartPosition() ?? '';
        $form->steps = implode("\n", $text?->getSteps() ?? []);
        $form->breathing = $text?->getBreathing() ?? '';
        $form->mistakes = implode("\n", $text?->getMistakes() ?? []);
        $form->easier = $text?->getEasier() ?? '';
        $form->harder = $text?->getHarder() ?? '';
        $form->mode = $exercise->getDosageMode()->value;
        $form->sets = (string) $exercise->getDefaultSets();
        $form->value = (string) $exercise->getDefaultValue();
        $form->rest = (string) $exercise->getDefaultRestSeconds();
        $form->videoUrl = $exercise->getVideoUrl() ?? '';

        return $form;
    }

    /** @return array<string, FieldError> par nom de champ ; vide si la saisie est valide */
    public function validate(): array
    {
        $errors = [];
        $name = self::line($this->name);
        if ($name === '') {
            $errors['name'] = new FieldError('health.form.error.name_required');
        } elseif (mb_strlen($name) > HealthExerciseText::NAME_MAX) {
            $errors['name'] = new FieldError('health.form.error.too_long', ['%max%' => HealthExerciseText::NAME_MAX]);
        }
        if (HealthCategory::tryFrom($this->category) === null) {
            $errors['category'] = new FieldError('health.form.error.choice_required');
        }
        if (HealthLevel::tryFrom($this->level) === null) {
            $errors['level'] = new FieldError('health.form.error.choice_required');
        }
        if ($this->areas === [] || count($this->parsedAreas()) !== count($this->areas)) {
            $errors['areas'] = new FieldError('health.form.error.areas_required');
        }
        if (count($this->parsedEquipment()) !== count($this->equipment)) {
            $errors['equipment'] = new FieldError('health.form.error.choice_invalid');
        }

        foreach ([
            'muscles'   => [$this->muscles, HealthExerciseText::MUSCLES_MAX],
            'start'     => [$this->start, HealthExerciseText::START_MAX],
            'breathing' => [$this->breathing, HealthExerciseText::BREATHING_MAX],
            'easier'    => [$this->easier, HealthExerciseText::VARIATION_MAX],
            'harder'    => [$this->harder, HealthExerciseText::VARIATION_MAX],
        ] as $field => [$value, $max]) {
            if (mb_strlen(self::line($value)) > $max) {
                $errors[$field] = new FieldError('health.form.error.too_long', ['%max%' => $max]);
            }
        }

        $steps = self::lines($this->steps);
        if ($steps === []) {
            $errors['steps'] = new FieldError('health.form.error.steps_required');
        } elseif (count($steps) > HealthExerciseText::STEPS_MAX) {
            $errors['steps'] = new FieldError('health.form.error.too_many_lines', ['%max%' => HealthExerciseText::STEPS_MAX]);
        } elseif (self::longestLine($steps) > HealthExerciseText::STEP_MAX) {
            $errors['steps'] = new FieldError('health.form.error.line_too_long', ['%max%' => HealthExerciseText::STEP_MAX]);
        }
        $mistakes = self::lines($this->mistakes);
        if (count($mistakes) > HealthExerciseText::MISTAKES_MAX) {
            $errors['mistakes'] = new FieldError('health.form.error.too_many_lines', ['%max%' => HealthExerciseText::MISTAKES_MAX]);
        } elseif (self::longestLine($mistakes) > HealthExerciseText::MISTAKE_MAX) {
            $errors['mistakes'] = new FieldError('health.form.error.line_too_long', ['%max%' => HealthExerciseText::MISTAKE_MAX]);
        }

        $mode = HealthDosageMode::tryFrom($this->mode);
        if ($mode === null) {
            $errors['mode'] = new FieldError('health.form.error.choice_required');
        }
        if (self::int($this->sets, HealthLimits::SETS_MIN, HealthLimits::SETS_MAX) === null) {
            $errors['sets'] = new FieldError('health.form.error.range', ['%min%' => HealthLimits::SETS_MIN, '%max%' => HealthLimits::SETS_MAX]);
        }
        if ($mode !== null && self::int($this->value, $mode->minValue(), $mode->maxValue()) === null) {
            $errors['value'] = new FieldError('health.form.error.range', ['%min%' => $mode->minValue(), '%max%' => $mode->maxValue()]);
        }
        if (self::int($this->rest, HealthLimits::REST_MIN, HealthLimits::REST_MAX) === null) {
            $errors['rest'] = new FieldError('health.form.error.range', ['%min%' => HealthLimits::REST_MIN, '%max%' => HealthLimits::REST_MAX]);
        }

        try {
            VideoUrl::normalize($this->videoUrl);
        } catch (\InvalidArgumentException $e) {
            $errors['video_url'] = new FieldError($e->getMessage(), ['%max%' => HealthLimits::VIDEO_URL_MAX]);
        }

        return $errors;
    }

    /**
     * Recopie la saisie VALIDÉE dans l'exercice ; les textes vont dans sa ligne de textes existante (modification)
     * ou dans une nouvelle ligne de la langue $locale (création).
     */
    public function applyTo(HealthExercise $exercise, string $locale): void
    {
        $mode = HealthDosageMode::from($this->mode);
        $exercise
            ->setCategory(HealthCategory::from($this->category))
            ->setLevel(HealthLevel::from($this->level))
            ->setBodyAreas($this->parsedAreas())
            ->setEquipment($this->parsedEquipment())
            ->setUnilateral($this->unilateral)
            ->setDosageMode($mode)
            ->setDefaultSets((int) self::int($this->sets, HealthLimits::SETS_MIN, HealthLimits::SETS_MAX))
            ->setDefaultValue((int) self::int($this->value, $mode->minValue(), $mode->maxValue()))
            ->setDefaultRestSeconds((int) self::int($this->rest, HealthLimits::REST_MIN, HealthLimits::REST_MAX))
            ->setVideoUrl(VideoUrl::normalize($this->videoUrl));

        $text = $exercise->getTexts()->first();
        if (!$text instanceof HealthExerciseText) {
            $text = (new HealthExerciseText())->setLocale(substr($locale, 0, 2));
            $exercise->addText($text);
        }
        $text->setName(self::line($this->name))
            ->setTargetMuscles(self::nullable(self::line($this->muscles)))
            ->setStartPosition(self::nullable(self::line($this->start)))
            ->setSteps(self::lines($this->steps))
            ->setBreathing(self::nullable(self::line($this->breathing)))
            ->setMistakes(self::lines($this->mistakes))
            ->setEasier(self::nullable(self::line($this->easier)))
            ->setHarder(self::nullable(self::line($this->harder)));
    }

    /** @return list<HealthBodyArea> */
    private function parsedAreas(): array
    {
        return array_values(array_filter(array_map(static fn (string $v): ?HealthBodyArea => HealthBodyArea::tryFrom($v), $this->areas)));
    }

    /** @return list<HealthEquipment> */
    private function parsedEquipment(): array
    {
        return array_values(array_filter(array_map(static fn (string $v): ?HealthEquipment => HealthEquipment::tryFrom($v), $this->equipment)));
    }

    /** Texte sur une ligne : caractères de contrôle et retours à la ligne remplacés par des espaces, espaces simples. */
    public static function line(string $text): string
    {
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text));
    }

    /**
     * Une entrée par ligne non vide, sans puce ni numéro en début de ligne.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $line = self::line((string) preg_replace('/^\s*(?:[-*\x{2022}]|\d{1,2}[.)])\s+/u', '', $raw));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @param list<string> $lines */
    private static function longestLine(array $lines): int
    {
        return $lines === [] ? 0 : max(array_map('mb_strlen', $lines));
    }

    private static function nullable(string $text): ?string
    {
        return $text === '' ? null : $text;
    }

    /** Entier saisi dans [$min, $max], sinon null. */
    private static function int(string $raw, int $min, int $max): ?int
    {
        $raw = trim($raw);
        if (preg_match('/^\d{1,6}$/', $raw) !== 1) {
            return null;
        }
        $value = (int) $raw;

        return $value >= $min && $value <= $max ? $value : null;
    }
}
