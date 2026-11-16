<?php

namespace App\Tests\Unit\Service\Health;

use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthEquipment;
use App\Entity\HealthExercise;
use App\Entity\HealthExerciseText;
use App\Entity\HealthExerciseUserPref;
use App\Entity\HealthLevel;
use App\Entity\User;
use App\Service\Health\HealthExerciseView;
use App\Service\Health\HealthExerciseViewBuilder;
use App\Service\Health\HealthLibraryFilter;
use App\Service\Health\TextFold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bibliothèque : choix des textes selon la langue, lien vidéo (le sien ou la préférence), masquage, tri, filtres
 * (miroir de health_library_controller.js) et repliement du texte de recherche (miroir de fold()).
 */
final class HealthLibraryTest extends TestCase
{
    private static function view(int $id, string $name, HealthCategory $category = HealthCategory::Strength, array $areas = [HealthBodyArea::Hip], array $equipment = [], HealthLevel $level = HealthLevel::Beginner, bool $custom = false, bool $hidden = false, ?string $muscles = null): HealthExerciseView
    {
        return new HealthExerciseView($id, $custom ? null : 'e'.$id, $custom, 'fr', $name, $category, $level, $areas, $equipment, false, HealthDosageMode::Reps, 3, 10, 30, $muscles, hidden: $hidden);
    }

    public function testFold(): void
    {
        self::assertSame('etirement du soleaire', TextFold::fold("  Étirement \t du   SOLÉAIRE "));
        self::assertSame('coeur ca ete noel', TextFold::fold('Cœur ÇA ÉTÉ NOËL'));
        self::assertSame(['pont', 'fessier'], TextFold::words(' Pont  FESSIER '));
        self::assertSame([], TextFold::words('   '));
    }

    public function testFromQueryIsTolerant(): void
    {
        $f = HealthLibraryFilter::fromQuery(['category' => 'core', 'area' => 'neck', 'level' => 'advanced', 'equipment' => 'none', 'q' => ['x'], 'hidden' => '1']);
        self::assertSame([HealthCategory::Core, null, HealthLevel::Advanced, 'none', '', true], [$f->category, $f->area, $f->level, $f->equipment, $f->query, $f->showHidden]);
        self::assertTrue($f->isActive());

        $g = HealthLibraryFilter::fromQuery(['equipment' => 'rope', 'q' => str_repeat('a', 100)]);
        self::assertNull($g->equipment);
        self::assertSame(80, mb_strlen($g->query));
        self::assertFalse(HealthLibraryFilter::fromQuery([])->isActive());
    }

    /** @return iterable<string, array{array<string, string>, list<int>}> */
    public static function filterProvider(): iterable
    {
        yield 'rien'                => [[], [1, 2, 3, 4]];
        yield 'catégorie'           => [['category' => 'core'], [2]];
        yield 'zone'                => [['area' => 'lower_leg'], [3]];
        yield 'niveau'              => [['level' => 'advanced'], [2]];
        yield 'sans matériel'       => [['equipment' => 'none'], [1, 3]];
        yield 'élastique'           => [['equipment' => 'band'], [2]];
        yield 'recherche accent'    => [['q' => 'MOLLÉT'], [3]];
        yield 'recherche muscles'   => [['q' => 'obliques'], [2]];
        yield 'deux mots'           => [['q' => 'pont fess'], [1]];
        yield 'mot absent'          => [['q' => 'pont zzz'], []];
        yield 'masqués affichés'    => [['hidden' => '1'], [1, 2, 3, 4, 5]];
        yield 'combinés'            => [['category' => 'strength', 'equipment' => 'none', 'q' => 'pont'], [1]];
    }

    /** @param array<string, string> $query */
    #[DataProvider('filterProvider')]
    public function testFilterMatches(array $query, array $expected): void
    {
        $views = [
            self::view(1, 'Pont fessier', muscles: 'Fessiers'),
            self::view(2, 'Pallof press', HealthCategory::Core, [HealthBodyArea::Core], [HealthEquipment::Band], HealthLevel::Advanced, muscles: 'Obliques'),
            self::view(3, 'Montées sur pointes', areas: [HealthBodyArea::LowerLeg, HealthBodyArea::FootAnkle], muscles: 'Mollets'),
            self::view(4, 'Mon exercice', equipment: [HealthEquipment::Chair], custom: true),
            self::view(5, 'Masqué', hidden: true),
        ];
        $filter = HealthLibraryFilter::fromQuery($query);
        self::assertSame($expected, array_values(array_map(static fn (HealthExerciseView $v): int => $v->id, array_filter($views, $filter->matches(...)))));
    }

    private static function exercise(int $id, ?User $owner, HealthCategory $category, array $texts, ?string $video = null): HealthExercise
    {
        $e = (new HealthExercise())->setUser($owner)->setSlug($owner ? null : 'e'.$id)->setCategory($category)->setVideoUrl($video)
            ->setBodyAreas([HealthBodyArea::Hip])->setEquipment([HealthEquipment::Step]);
        foreach ($texts as $locale => $name) {
            $e->addText((new HealthExerciseText())->setLocale($locale)->setName($name)->setSteps(['s1', 's2'])->setTargetMuscles('m '.$locale));
        }
        (new \ReflectionProperty(HealthExercise::class, 'id'))->setValue($e, $id);

        return $e;
    }

    public function testViewPicksTheLanguageVideoAndHiddenState(): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 5);
        $provided = self::exercise(1, null, HealthCategory::Strength, ['fr' => 'Pont fessier', 'en' => 'Glute bridge'], 'https://ignored.example/');
        $pref = (new HealthExerciseUserPref($user, $provided))->setVideoUrl('https://mine.example/v')->setHidden(true);

        $fr = HealthExerciseViewBuilder::build($provided, $pref, 'fr');
        $en = HealthExerciseViewBuilder::build($provided, $pref, 'en_GB');
        self::assertSame(['Pont fessier', 'fr', 'm fr'], [$fr->name, $fr->textLocale, $fr->targetMuscles]);
        self::assertSame(['Glute bridge', 'en', 'm en'], [$en->name, $en->textLocale, $en->targetMuscles]);
        self::assertSame('https://mine.example/v', $fr->videoUrl, 'exercice fourni : lien personnel, jamais celui de l\'exercice');
        self::assertTrue($fr->hidden);
        self::assertSame(['hip'], $fr->areaValues());
        self::assertSame(['step'], $fr->equipmentValues());
        self::assertSame('pont fessier m fr', $fr->searchText());
        self::assertSame(['id' => 1, 'name' => 'Pont fessier', 'lang' => 'fr', 'category' => 'strength', 'mode' => 'reps', 'unilateral' => false, 'sets' => 3, 'value' => 10, 'rest' => 45, 'steps' => ['s1', 's2'], 'breathing' => null, 'url' => '/x'], $fr->toPlayer('/x'));
        self::assertNull(HealthExerciseViewBuilder::build($provided, null, 'fr')->videoUrl);

        $custom = self::exercise(2, $user, HealthCategory::Core, ['en' => 'My plank'], 'https://own.example/');
        $view = HealthExerciseViewBuilder::build($custom, null, 'fr');
        self::assertSame(['My plank', 'en', 'https://own.example/', false, true], [$view->name, $view->textLocale, $view->videoUrl, $view->hidden, $view->custom]);
        // Langue inconnue : français, sinon la seule langue disponible
        self::assertSame('Pont fessier', HealthExerciseViewBuilder::build($provided, null, 'de')->name);
        self::assertTrue($custom->isVisibleTo($user) && $custom->isOwnedBy($user) && $provided->isVisibleTo($user) && !$provided->isOwnedBy($user));
        $other = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($other, 6);
        self::assertFalse($custom->isVisibleTo($other));
    }

    public function testSortByCategoryThenCustomByNameThenProvidedInMigrationOrder(): void
    {
        $views = [
            self::view(10, 'Zèbre', HealthCategory::Stretching),
            self::view(11, 'B fourni', HealthCategory::Strength),
            self::view(12, 'A fourni', HealthCategory::Strength),
            self::view(13, 'écart perso', HealthCategory::Strength, custom: true),
            self::view(14, 'Autre perso', HealthCategory::Strength, custom: true),
            self::view(15, 'Gainage', HealthCategory::Core),
        ];
        self::assertSame([14, 13, 11, 12, 15, 10], array_map(static fn (HealthExerciseView $v): int => $v->id, HealthExerciseViewBuilder::sort($views)));
    }
}
