<?php
namespace App\Controller\Race;

use App\Entity\RaceShare;
use App\Repository\RaceShareRepository;
use App\Service\Route\ScenarioViewBuilder;
use App\Service\Share\PlanCalendar;
use App\Service\Share\PublicPlanBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Page publique d'un plan de course partagé (/p/{token}, sans compte : ^/p/ est en PUBLIC_ACCESS dans security.yaml)
 * et son fichier d'agenda (/p/{token}/calendar.ics). Réglages côté propriétaire : RaceShareController.
 *
 * - jeton mal formé, inconnu, régénéré ou partage désactivé : la même 404 (aucune requête si le format est faux) ;
 * - données : PublicPlanBuilder (liste blanche, voir son docblock) ; aucune donnée privée ni autre scénario ;
 * - langue : ?lang=fr|en, sinon la meilleure de fr / en selon Accept-Language, sinon fr ; jamais la session ;
 * - ni session, ni cookie, ni jeton CSRF, ni message flash : la page marche dans un navigateur sans cookie ;
 * - en-têtes : X-Robots-Tag noindex, Referrer-Policy strict-origin-when-cross-origin (les tuiles OpenStreetMap exigent
 *   un Referer, mais le jeton du chemin n'en sort pas), Cache-Control no-store (désactiver prend effet aussitôt).
 *
 * @phpstan-import-type PublicView from PublicPlanBuilder
 */
final class RacePublicController extends AbstractController
{
    public const LOCALES = ['fr', 'en'];

    public function __construct(
        private readonly RaceShareRepository $shares,
        private readonly ScenarioViewBuilder $scenarioBuilder,
        private readonly PublicPlanBuilder $builder,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/p/{token}', name: 'app_race_public', requirements: ['token' => '[^/]+'], methods: ['GET'])]
    public function show(string $token, Request $request): Response
    {
        $share = $this->findShare($token);
        $locale = self::locale($request);
        $request->setLocale($locale);
        $view = $this->view($share);

        $response = $this->localeSwitcher->runWithLocale($locale, fn (): Response => $this->render('race/public.html.twig', [
            'view' => $view,
            'token' => $share->getToken(),
            'locale' => $locale,
            'dateLabel' => self::longDate($view['race']['date'], $locale),
        ]));

        return self::secure($response);
    }

    #[Route('/p/{token}/calendar.ics', name: 'app_race_public_ics', requirements: ['token' => '[^/]+'], methods: ['GET'])]
    public function calendar(string $token, Request $request): Response
    {
        $share = $this->findShare($token);
        $locale = self::locale($request);
        $view = $this->view($share);

        $t = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, null, $locale);
        $name = $view['displayName'];
        $ics = PlanCalendar::build($view, [
            'summary' => null !== $name ? $t('race_public.ics_summary_named', ['%race%' => $view['race']['name'], '%name%' => $name]) : $view['race']['name'],
            'by' => null !== $name ? $t('race_public.ics_by', ['%name%' => $name]) : null,
            'start' => $t('race_public.start'),
            'finish' => $t('race_public.finish'),
            'aid' => $t('race_public.aid_station'),
            'day' => $t('race_public.day_offset', ['%n%' => '%n%']),
            'unscheduled' => $t('race_public.no_times'),
            'note' => $t('race_public.planned_note'),
        ], $this->generateUrl('app_race_public', ['token' => $share->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            substr(hash('sha256', 'race-share|'.$share->getToken()), 0, 32).'@'.$request->getHost(),
            new \DateTimeImmutable(),
            'fr' === $locale ? ',' : '.');
        if (null === $ics) {
            throw $this->createNotFoundException(); // course sans date : pas d'événement (le bouton n'est pas affiché)
        }

        $slug = (string) (new AsciiSlugger())->slug($view['race']['name'])->lower();
        $response = new Response($ics, Response::HTTP_OK, ['Content-Type' => 'text/calendar; charset=utf-8']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            ('' !== $slug ? mb_substr($slug, 0, 80) : 'course').'.ics',
        ));

        return self::secure($response);
    }

    /** Même 404 pour tous les cas (voir RaceShareRepository::findActiveByToken). */
    private function findShare(string $token): RaceShare
    {
        return $this->shares->findActiveByToken($token) ?? throw $this->createNotFoundException();
    }

    /** @return PublicView */
    private function view(RaceShare $share): array
    {
        $race = $share->getRace();
        $scenarioView = $this->scenarioBuilder->build($race);

        return $this->builder->build(
            $race,
            $share,
            $scenarioView['route'],
            $scenarioView['checkpoints'],
            $scenarioView['routeDistanceM'],
            PublicPlanBuilder::selectScenario($scenarioView['scenarios'], $share->getScenario()?->getId()),
        );
    }

    /** ?lang=fr|en, sinon Accept-Language (fr ou en), sinon fr. */
    public static function locale(Request $request): string
    {
        $lang = $request->query->all()['lang'] ?? null; // all() : un tableau (?lang[]=) est ignoré, sans erreur 400
        if (\is_string($lang) && \in_array($lang, self::LOCALES, true)) {
            return $lang;
        }

        return $request->getPreferredLanguage(self::LOCALES) ?? self::LOCALES[0];
    }

    /** "dimanche 14 juin 2026" / "Sunday, June 14, 2026" (intl), sinon "14/06/2026". */
    private static function longDate(?string $date, string $locale): ?string
    {
        if (null === $date) {
            return null;
        }
        $day = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        if (\extension_loaded('intl')) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC');
            $label = $formatter->format($day);
            if (\is_string($label) && '' !== $label) {
                return $label;
            }
        }

        return $day->format('fr' === $locale ? 'd/m/Y' : 'm/d/Y');
    }

    private static function secure(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cache-Control', 'no-store, private');
        // Pas de réécriture du Cache-Control par l'écouteur de session (propriétaire connecté qui ouvre l'aperçu)
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
