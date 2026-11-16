<?php

namespace App\Tests\Unit\Service\Notifications;

use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Entity\Sport;
use App\Service\Gear\GearUsage;
use App\Service\Goals\GoalMetric;
use App\Service\Goals\SportGoalProgress;
use App\Service\Notifications\RaceTodoRow;
use App\Service\Notifications\RecentActivityRow;
use App\Service\Notifications\StravaState;
use App\Service\Notifications\TodoBuilder;
use App\Service\Notifications\TodoInput;
use App\Service\Notifications\TodoItem;
use App\Service\Notifications\TodoPresenter;
use App\Service\Notifications\TodoText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * Textes de la cloche avec les VRAIS fichiers de traduction (fr / en), nombres, listes, liens ; clés de la
 * section « notifications » identiques dans les deux langues.
 */
final class TodoPresenterTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';
    private const XSS = '<img src=x onerror="alert(1)">&amp;</script>';

    private static function presenter(): TodoPresenter
    {
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', self::ROOT.'/translations/messages.fr.yaml', 'fr');
        $translator->addResource('yaml', self::ROOT.'/translations/messages.en.yaml', 'en');

        $urls = new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/'.$name.($parameters !== [] ? '?'.http_build_query($parameters) : '');
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };

        return new TodoPresenter($translator, $urls);
    }

    private static function sport(string $slug): Sport
    {
        return (new Sport())->setSlug($slug)->setIcon('bi-x')->setColor('#000000');
    }

    /** @param array<string, string|int|float|TodoText|list<TodoText>> $params */
    private static function t(string $key, array $params = [], string $locale = 'fr'): string
    {
        return self::presenter()->text(new TodoText($key, $params), $locale);
    }

    public function testRaceTitlesInBothLanguages(): void
    {
        self::assertSame('Marathon d\'Annecy dans 7 jours', self::t('notifications.race_in_days', ['%name%' => 'Marathon d\'Annecy', '%count%' => 7]));
        self::assertSame('C\'est le jour J : Marathon d\'Annecy', self::t('notifications.race_today', ['%name%' => 'Marathon d\'Annecy']));
        self::assertSame('Demain : Ultra', self::t('notifications.race_tomorrow', ['%name%' => 'Ultra']));
        self::assertSame('Ultra dans 18 jours, sans plan de course', self::t('notifications.race_no_plan', ['%name%' => 'Ultra', '%count%' => 18]));
        self::assertSame('Ultra dans 9 jours : pas encore de temps de passage', self::t('notifications.race_no_splits', ['%name%' => 'Ultra', '%count%' => 9]));
        self::assertSame('Prévision météo du jour J disponible', self::t('notifications.race_weather'));
        self::assertSame('Ultra in 9 days: no split times yet', self::t('notifications.race_no_splits', ['%name%' => 'Ultra', '%count%' => 9], 'en'));
    }

    public function testGoalTexts(): void
    {
        $now = new \DateTimeImmutable('2026-11-22 12:00:00');
        $items = (new TodoBuilder())->build(new TodoInput(goals: [
            new SportGoalProgress(self::sport('ride'), [new GoalMetric(GoalMetric::SESSIONS, 3, 2)]),
            new SportGoalProgress(self::sport('run'), [
                new GoalMetric(GoalMetric::DISTANCE, 18.0, 30.0),
                new GoalMetric(GoalMetric::SESSIONS, 2, 3),
                new GoalMetric(GoalMetric::ELEVATION, 0, 1000),
            ]),
            new SportGoalProgress(self::sport('trail'), [new GoalMetric(GoalMetric::DISTANCE, 20.0, 32.5), new GoalMetric(GoalMetric::DURATION, 210, 300)]),
        ]), $now);
        $fr = array_column(self::presenter()->present($items, 'fr'), 'title');
        $en = array_column(self::presenter()->present($items, 'en'), 'title');

        self::assertSame([
            'Course : il te reste 12 km, 1 séance et 1 000 m D+',
            'Trail : il te reste 12,5 km et 1h30',
            'Objectif vélo atteint cette semaine',
        ], $fr);
        self::assertSame([
            'Run: 12 km, 1 session and 1,000 m gain left',
            'Trail: 12.5 km and 1h 30m left',
            'Ride goal reached this week',
        ], $en);
    }

    public function testGearAndActivityTexts(): void
    {
        $gear = (new Gear())->setType(GearType::Shoes)->setBrand((new GearBrand())->setName('Nike'))->setModel('Pegasus 40')->setNickname('Rouges')->setMaxDistanceM(700_000);
        (new \ReflectionProperty(Gear::class, 'id'))->setValue($gear, 12);
        $now = new \DateTimeImmutable('2026-11-16 10:00:00');
        $items = (new TodoBuilder())->build(new TodoInput(
            wearAlerts: [['gear' => $gear, 'usage' => new GearUsage(80, 1_012_400.0, 360000, null, 0, 700_000)]],
            activeGearTypes: [GearType::Shoes],
            recentActivities: [
                new RecentActivityRow(88, 'Grand Colombier', $now->modify('-3 days'), 'trail', 7500, null, true, false),
                new RecentActivityRow(89, null, $now->modify('-1 day'), 'run', 1800, null, false, false),
            ],
        ), $now);

        $out = self::presenter()->present($items, 'fr');
        self::assertSame([
            ['key' => 'gear:12:worn', 'severity' => 'urgent', 'icon' => 'bi-x-octagon-fill', 'title' => 'Tes Nike Pegasus 40 (Rouges) sont à remplacer',
                'subtitle' => '1 012 km, 144 % de leur durée de vie', 'url' => '/app_settings?tab=gear#gear-12'],
            ['key' => 'fuel:activity:88', 'severity' => 'info', 'icon' => 'bi-cup-straw', 'title' => 'Ravitaillement à noter : Grand Colombier',
                'subtitle' => 'Sortie de 2h05, il y a 3 jours', 'url' => '/app_activity_show?id=88#sp-fuel-title'],
            ['key' => 'gear:missing:89', 'severity' => 'info', 'icon' => 'bi-tag', 'title' => '1 activité récente sans matériel',
                'subtitle' => 'Activité sans titre', 'url' => '/app_activity_show?id=89#act-gear-select'],
        ], $out);

        $en = self::presenter()->present($items, 'en');
        self::assertSame('Your Nike Pegasus 40 (Rouges) need replacing', $en[0]['title']);
        self::assertSame('1,012 km, 144 % of their lifespan', $en[0]['subtitle']);
        self::assertSame('2h 05m session, 3 days ago', $en[1]['subtitle']);
    }

    public function testStravaTexts(): void
    {
        $items = (new TodoBuilder())->build(new TodoInput(strava: new StravaState(false, false, false)), new \DateTimeImmutable());
        $out = self::presenter()->present($items, 'fr');

        self::assertSame('Connecte ton compte Strava', $out[0]['title']);
        self::assertSame('/app_oauth_strava', $out[0]['url']);
    }

    /** Le texte saisi est recopié tel quel (texte brut) : c'est le navigateur qui l'insère avec textContent. */
    public function testUserTextIsCopiedVerbatim(): void
    {
        $race = new RaceTodoRow(5, self::XSS.' $& $1 %count%', new \DateTimeImmutable('2026-11-23'), self::XSS, false, 0, 0, 0);
        $items = (new TodoBuilder())->build(new TodoInput(races: [$race]), new \DateTimeImmutable('2026-11-16 08:00'));
        $out = self::presenter()->present($items, 'fr');

        self::assertSame(self::XSS.' $& $1 %count% dans 7 jours, sans plan de course', $out[0]['title']);
        self::assertSame('/app_race_plan?id=5', $out[0]['url']);
    }

    public function testUnknownSportFallsBackToItsSlug(): void
    {
        self::assertSame('Objectif paddle atteint cette semaine', self::t('notifications.goal_reached', [
            '%sport%' => new TodoText('sport.paddle'), '%sport_lc%' => new TodoText('sport.paddle', [], true),
        ]));
    }

    /** @return iterable<string, array{int|float, string, string}> */
    public static function numberProvider(): iterable
    {
        yield 'entier'                  => [12, 'fr', '12'];
        yield 'milliers fr'             => [1000, 'fr', '1 000'];
        yield 'milliers en'             => [1000, 'en', '1,000'];
        yield 'décimal entier'          => [12.0, 'fr', '12'];
        yield 'dixième fr'              => [12.5, 'fr', '12,5'];
        yield 'dixième en'              => [12.5, 'en', '12.5'];
        yield 'demi vers le haut'       => [12.25, 'fr', '12,3'];
        yield 'presque entier'          => [12.96, 'fr', '13'];
        yield 'petit'                   => [0.05, 'fr', '0,1'];
        yield 'milliers et dixième'     => [1234.5, 'fr', '1 234,5'];
        yield 'négatif'                 => [-2.5, 'en', '-2.5'];
    }

    #[DataProvider('numberProvider')]
    public function testNumbers(int|float $value, string $locale, string $expected): void
    {
        self::assertSame($expected, TodoPresenter::number($value, $locale));
    }

    public function testListJoining(): void
    {
        $p = self::presenter();
        $parts = [new TodoText('notifications.left_sessions_one'), new TodoText('notifications.left_distance', ['%value%' => 3.5]), new TodoText('notifications.left_elevation', ['%value%' => 200])];

        self::assertSame('Course : il te reste 1 séance', $p->text(new TodoText('notifications.goal_left', ['%sport%' => new TodoText('sport.run'), '%left%' => [$parts[0]]]), 'fr'));
        self::assertSame('Course : il te reste 1 séance et 3,5 km', $p->text(new TodoText('notifications.goal_left', ['%sport%' => new TodoText('sport.run'), '%left%' => [$parts[0], $parts[1]]]), 'fr'));
        self::assertSame('Run: 1 session, 3.5 km and 200 m gain left', $p->text(new TodoText('notifications.goal_left', ['%sport%' => new TodoText('sport.run'), '%left%' => $parts]), 'en'));
    }

    // ── Traductions ───────────────────────────────────────────────────

    public function testNotificationsSectionHasTheSameKeysAndPlaceholdersInBothLanguages(): void
    {
        $fr = Yaml::parseFile(self::ROOT.'/translations/messages.fr.yaml')['notifications'] ?? null;
        $en = Yaml::parseFile(self::ROOT.'/translations/messages.en.yaml')['notifications'] ?? null;
        self::assertIsArray($fr);
        self::assertIsArray($en);
        $frKeys = array_keys($fr);
        $enKeys = array_keys($en);
        sort($frKeys);
        sort($enKeys);
        self::assertSame($frKeys, $enKeys);

        foreach ($fr as $key => $value) {
            self::assertIsString($value, "fr $key");
            self::assertIsString($en[$key], "en $key");
            self::assertNotSame('', trim($en[$key]), "en $key");
            // %sport_lc% (minuscules, français) et %sport% désignent le même paramètre
            $norm = static function (string $text): array {
                preg_match_all('/%\w+%/', str_replace('%sport_lc%', '%sport%', $text), $m);
                sort($m[0]);

                return $m[0];
            };
            self::assertSame($norm($value), $norm($en[$key]), "$key placeholders");
        }
    }

    /** Toutes les clés produites par le builder (et le partial Twig) existent dans les deux langues. */
    public function testEveryKeyUsedExists(): void
    {
        $sources = (string) file_get_contents(self::ROOT.'/src/Service/Notifications/TodoBuilder.php')
            .(string) file_get_contents(self::ROOT.'/templates/_notifications.html.twig');
        preg_match_all("/'((?:notifications|objectives\\.by_sport)\\.[a-z0-9_.]+)'/", $sources, $m);
        $used = array_values(array_unique($m[1]));
        // Clés construites : notifications.gear_{type}_{statut} et _usage
        foreach (['shoes', 'bike'] as $type) {
            foreach (['worn', 'warn', 'usage'] as $suffix) {
                $used[] = "notifications.gear_{$type}_{$suffix}";
            }
        }
        self::assertGreaterThan(40, count($used));

        foreach (['fr', 'en'] as $locale) {
            $messages = Yaml::parseFile(self::ROOT.'/translations/messages.'.$locale.'.yaml');
            foreach ($used as $key) {
                $node = $messages;
                foreach (explode('.', $key) as $part) {
                    $node = is_array($node) ? ($node[$part] ?? null) : null;
                }
                self::assertIsString($node, "$key missing in messages.$locale.yaml");
            }
        }
    }

    public function testSeveritiesAreKnown(): void
    {
        self::assertSame(['urgent', 'warning', 'info', 'success'], [TodoItem::URGENT, TodoItem::WARNING, TodoItem::INFO, TodoItem::SUCCESS]);
    }
}
