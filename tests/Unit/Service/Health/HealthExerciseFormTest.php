<?php

namespace App\Tests\Unit\Service\Health;

use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthEquipment;
use App\Entity\HealthExercise;
use App\Entity\HealthLevel;
use App\Entity\User;
use App\Service\Health\FieldError;
use App\Service\Health\HealthExerciseForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercice personnel : validation de la saisie (bornes, longueurs, listes, vidéo) et application à l'entité. */
final class HealthExerciseFormTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(array $override = []): array
    {
        return array_merge([
            'name' => '  Gainage   maison ', 'category' => 'core', 'level' => 'intermediate', 'areas' => ['core', 'back'],
            'equipment' => ['band'], 'unilateral' => '1', 'muscles' => 'Abdos', 'start' => 'Allongé', 'steps' => "1. Monte\n- Tiens\n\n* Redescends",
            'breathing' => 'Expire', 'mistakes' => "Cambrer\r\nBloquer", 'easier' => 'Genoux au sol', 'harder' => 'Une jambe',
            'mode' => 'time', 'sets' => '3', 'value' => '45', 'rest' => '30', 'video_url' => ' https://example.com/v ',
        ], $override);
    }

    /** @return array<string, string> champ => clé d'erreur */
    private static function errors(array $data): array
    {
        return array_map(static fn (FieldError $e): string => $e->key, HealthExerciseForm::fromRequest($data)->validate());
    }

    public function testValidInputHasNoError(): void
    {
        self::assertSame([], self::errors(self::valid()));
    }

    public function testApplyToFillsTheExerciseAndOneTextRowInTheInterfaceLanguage(): void
    {
        $user = new User();
        $exercise = (new HealthExercise())->setUser($user);
        HealthExerciseForm::fromRequest(self::valid())->applyTo($exercise, 'fr_FR');

        self::assertSame(HealthCategory::Core, $exercise->getCategory());
        self::assertSame(HealthLevel::Intermediate, $exercise->getLevel());
        self::assertSame([HealthBodyArea::Core, HealthBodyArea::Back], $exercise->getBodyAreas());
        self::assertSame([HealthEquipment::Band], $exercise->getEquipment());
        self::assertTrue($exercise->isUnilateral());
        self::assertSame([HealthDosageMode::Time, 3, 45, 30], [$exercise->getDosageMode(), $exercise->getDefaultSets(), $exercise->getDefaultValue(), $exercise->getDefaultRestSeconds()]);
        self::assertSame('https://example.com/v', $exercise->getVideoUrl());
        self::assertCount(1, $exercise->getTexts());
        $text = $exercise->textFor('en');
        self::assertNotNull($text);
        self::assertSame('fr', $text->getLocale());
        self::assertSame('Gainage maison', $text->getName());
        self::assertSame(['Monte', 'Tiens', 'Redescends'], $text->getSteps());
        self::assertSame(['Cambrer', 'Bloquer'], $text->getMistakes());
        self::assertSame($text, $exercise->textFor('fr'), 'un exercice personnel est affiché tel quel dans toutes les langues');

        // Modification : même ligne de textes, langue d'origine gardée, champs vidés -> null
        HealthExerciseForm::fromRequest(self::valid(['name' => 'Renommé', 'muscles' => '', 'video_url' => '', 'equipment' => []]))->applyTo($exercise, 'en');
        self::assertCount(1, $exercise->getTexts());
        self::assertSame(['fr', 'Renommé', null], [$text->getLocale(), $text->getName(), $text->getTargetMuscles()]);
        self::assertNull($exercise->getVideoUrl());
        self::assertSame([], $exercise->getEquipment());
    }

    public function testFromExerciseRoundTrips(): void
    {
        $exercise = (new HealthExercise())->setUser(new User());
        $form = HealthExerciseForm::fromRequest(self::valid());
        $form->applyTo($exercise, 'fr');
        $back = HealthExerciseForm::fromExercise($exercise, 'en');

        self::assertSame(['Gainage maison', 'core', 'intermediate', ['core', 'back'], ['band'], true, "Monte\nTiens\nRedescends", "Cambrer\nBloquer", 'time', '3', '45', '30', 'https://example.com/v'],
            [$back->name, $back->category, $back->level, $back->areas, $back->equipment, $back->unilateral, $back->steps, $back->mistakes, $back->mode, $back->sets, $back->value, $back->rest, $back->videoUrl]);
        self::assertSame([], $back->validate());
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, string>}> */
    public static function invalidProvider(): iterable
    {
        yield 'nom vide'              => [['name' => '   '], ['name' => 'health.form.error.name_required']];
        yield 'nom trop long'         => [['name' => str_repeat('é', 81)], ['name' => 'health.form.error.too_long']];
        yield 'nom à 80'              => [['name' => str_repeat('é', 80)], []];
        yield 'catégorie inconnue'    => [['category' => 'cardio'], ['category' => 'health.form.error.choice_required']];
        yield 'niveau inconnu'        => [['level' => 'pro'], ['level' => 'health.form.error.choice_required']];
        yield 'aucune zone'           => [['areas' => []], ['areas' => 'health.form.error.areas_required']];
        yield 'zone inconnue'         => [['areas' => ['core', 'neck']], ['areas' => 'health.form.error.areas_required']];
        yield 'zones pas une liste'   => [['areas' => 'core'], ['areas' => 'health.form.error.areas_required']];
        yield 'matériel inconnu'      => [['equipment' => ['rope']], ['equipment' => 'health.form.error.choice_invalid']];
        yield 'aucune étape'          => [['steps' => " \n - \n"], ['steps' => 'health.form.error.steps_required']];
        yield 'trop d\'étapes'        => [['steps' => implode("\n", range(1, 9))], ['steps' => 'health.form.error.too_many_lines']];
        yield 'étape trop longue'     => [['steps' => str_repeat('a', 301)], ['steps' => 'health.form.error.line_too_long']];
        yield 'trop d\'erreurs'       => [['mistakes' => implode("\n", range(1, 7))], ['mistakes' => 'health.form.error.too_many_lines']];
        yield 'erreur trop longue'    => [['mistakes' => str_repeat('a', 201)], ['mistakes' => 'health.form.error.line_too_long']];
        yield 'muscles trop longs'    => [['muscles' => str_repeat('a', 161)], ['muscles' => 'health.form.error.too_long']];
        yield 'départ trop long'      => [['start' => str_repeat('a', 501)], ['start' => 'health.form.error.too_long']];
        yield 'respiration trop longue' => [['breathing' => str_repeat('a', 256)], ['breathing' => 'health.form.error.too_long']];
        yield 'variante trop longue'  => [['easier' => str_repeat('a', 301), 'harder' => str_repeat('b', 301)], ['easier' => 'health.form.error.too_long', 'harder' => 'health.form.error.too_long']];
        yield 'mode inconnu'          => [['mode' => 'distance'], ['mode' => 'health.form.error.choice_required']];
        yield 'séries à 0'            => [['sets' => '0'], ['sets' => 'health.form.error.range']];
        yield 'séries à 11'           => [['sets' => '11'], ['sets' => 'health.form.error.range']];
        yield 'séries décimales'      => [['sets' => '2.5'], ['sets' => 'health.form.error.range']];
        yield 'séries négatives'      => [['sets' => '-1'], ['sets' => 'health.form.error.range']];
        yield 'durée à 4 s'           => [['value' => '4'], ['value' => 'health.form.error.range']];
        yield 'durée à 601 s'         => [['value' => '601'], ['value' => 'health.form.error.range']];
        yield 'répétitions à 101'     => [['mode' => 'reps', 'value' => '101'], ['value' => 'health.form.error.range']];
        yield 'répétitions à 1'       => [['mode' => 'reps', 'value' => '1'], []];
        yield 'repos à 601'           => [['rest' => '601'], ['rest' => 'health.form.error.range']];
        yield 'repos à 0'             => [['rest' => '0'], []];
        yield 'vidéo javascript:'     => [['video_url' => 'javascript:alert(1)'], ['video_url' => 'health.form.error.video_invalid']];
        yield 'vidéo trop longue'     => [['video_url' => 'https://e.com/'.str_repeat('a', 490)], ['video_url' => 'health.form.error.video_too_long']];
    }

    /**
     * @param array<string, mixed>  $override
     * @param array<string, string> $expected
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidInputs(array $override, array $expected): void
    {
        self::assertSame($expected, self::errors(self::valid($override)));
    }

    public function testRangeErrorsCarryTheirBoundsForTheMessage(): void
    {
        $errors = HealthExerciseForm::fromRequest(self::valid(['mode' => 'reps', 'value' => '500']))->validate();
        self::assertSame(['%min%' => 1, '%max%' => 100], $errors['value']->params);
    }

    public function testLinesHelper(): void
    {
        self::assertSame(['a', 'b c', 'd'], HealthExerciseForm::lines("1) a\r\n  2. b   c \n\n• d"));
        self::assertSame('a b', HealthExerciseForm::line("a\t\n b\x00"));
        // Un tiret au milieu d'une ligne n'est pas une puce
        self::assertSame(['Talon - pointe'], HealthExerciseForm::lines('Talon - pointe'));
    }

    public function testNonScalarPostValuesAreIgnored(): void
    {
        $form = HealthExerciseForm::fromRequest(['name' => ['x'], 'areas' => [['nested'], 'core'], 'unilateral' => 'no']);
        self::assertSame('', $form->name);
        self::assertSame(['core'], $form->areas);
        self::assertFalse($form->unilateral);
    }
}
