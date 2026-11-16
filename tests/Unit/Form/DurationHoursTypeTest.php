<?php

namespace App\Tests\Unit\Form;

use App\Form\DataTransformer\HoursToMinutesTransformer;
use App\Form\DurationHoursType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

/** Saisie des durées d'objectif en heures ("5", "5,5", "5.5", "5:30") stockées en minutes. */
class DurationHoursTypeTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function validProvider(): iterable
    {
        yield 'hours'                  => ['5', 300];
        yield 'decimal comma'          => ['5,5', 330];
        yield 'decimal point'          => ['5.5', 330];
        yield 'h:mm'                   => ['5:30', 330];
        yield 'h:mm zero minutes'      => ['5:00', 300];
        yield 'under one hour'         => ['0:45', 45];
        yield 'quarter as decimal'     => ['1,25', 75];
        yield 'rounded to the minute'  => ['1,333', 80];
        yield '5h'                     => ['5h', 300];
        yield '5h30'                   => ['5h30', 330];
        yield 'spaces and upper case'  => [' 5 H 30 ', 330];
        yield 'zero (range checked by WeeklyGoal)' => ['0', 0];
        yield 'large (range checked by WeeklyGoal)' => ['150', 9000];
    }

    #[DataProvider('validProvider')]
    public function testParsesHours(string $input, int $minutes): void
    {
        self::assertSame($minutes, (new HoursToMinutesTransformer())->reverseTransform($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProvider(): iterable
    {
        yield 'text'                => ['abc'];
        yield 'negative'            => ['-5'];
        yield 'minutes over 59'     => ['5:75'];
        yield 'one-digit minutes'   => ['5:3'];
        yield 'two separators'      => ['5,5,5'];
        yield 'trailing separator'  => ['5,'];
        yield 'leading separator'   => [',5'];
        yield 'colon without hours' => [':30'];
        yield 'four-digit hours'    => ['1000'];
        yield 'units in words'      => ['5 heures'];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsUnreadableInput(string $input): void
    {
        $this->expectException(TransformationFailedException::class);
        (new HoursToMinutesTransformer())->reverseTransform($input);
    }

    public function testEmptyMeansNoTarget(): void
    {
        $transformer = new HoursToMinutesTransformer();
        self::assertNull($transformer->reverseTransform(''));
        self::assertNull($transformer->reverseTransform('   '));
        self::assertNull($transformer->reverseTransform(null));
        self::assertSame('', $transformer->transform(null));
    }

    public function testDisplaysWholeHoursOrHoursAndMinutes(): void
    {
        $transformer = new HoursToMinutesTransformer();
        self::assertSame('5', $transformer->transform(300));
        self::assertSame('5:30', $transformer->transform(330));
        self::assertSame('0:45', $transformer->transform(45));
        self::assertSame('12:05', $transformer->transform(725));
        // Aller-retour stable
        foreach ([1, 45, 60, 330, 725, 6000] as $minutes) {
            self::assertSame($minutes, $transformer->reverseTransform($transformer->transform($minutes)));
        }
    }

    private function factory(): FormFactoryInterface
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['settings.goals.error.duration_invalid' => 'Durée invalide : écris par exemple 5, 5,5 ou 5:30.'], 'fr');

        return Forms::createFormFactoryBuilder()
            ->addType(new DurationHoursType($translator))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    public function testFieldSubmitsMinutes(): void
    {
        $form = $this->factory()->create(DurationHoursType::class);
        $form->submit('5,5');

        self::assertTrue($form->isSynchronized());
        self::assertSame(330, $form->getData());
        self::assertSame('5:30', $form->getViewData(), 'réaffichée sous la forme normalisée');
    }

    public function testInvalidInputKeepsTheTextAndShowsTheTranslatedMessage(): void
    {
        $form = $this->factory()->create(DurationHoursType::class);
        $form->submit('5:75');

        self::assertFalse($form->isSynchronized());
        self::assertFalse($form->isValid());
        self::assertSame('5:75', $form->getViewData(), 'la saisie reste affichée');
        self::assertSame('Durée invalide : écris par exemple 5, 5,5 ou 5:30.', $form->getErrors()[0]->getMessage());
    }

    public function testExistingMinutesAreShownInHours(): void
    {
        $form = $this->factory()->create(DurationHoursType::class, 330);

        self::assertSame('5:30', $form->createView()->vars['value']);
    }
}
