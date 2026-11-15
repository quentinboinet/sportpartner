<?php
namespace App\Controller\Race;

use App\Entity\Race;
use App\Entity\RaceIntent;
use App\Entity\User;
use App\Repository\RaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/courses', name: 'app_races')]
#[IsGranted('ROLE_USER')]
class RaceController extends AbstractController
{
    private const VIEWS = ['list', 'calendar'];

    #[Route('', name: '', methods: ['GET'])]
    public function index(RaceRepository $repo, Request $request, #[CurrentUser] User $user): Response
    {
        $year   = $request->query->getInt('year', 0) ?: null;
        $intent = $request->query->get('intent');
        $view   = in_array($request->query->get('view'), self::VIEWS, true) ? $request->query->get('view') : 'list';

        $races = $repo->findByUser($user, $year, $intent);
        $years = $repo->getYearsForUser($user);

        // Ensure current year is always in the year list for the filter
        $currentYear = (int) date('Y');
        if (!in_array($currentYear, $years, true)) {
            $years[] = $currentYear;
            sort($years);
        }

        $racesData = [];
        $events    = [];
        $undated   = [];
        foreach ($races as $race) {
            $data = $this->toArray($race);
            $racesData[$race->getId()] = $data;

            if ($race->getRaceDate() === null) {
                $undated[] = $race;
                continue;
            }
            $events[] = [
                'id'            => (string) $race->getId(),
                'title'         => $race->getName(),
                'start'         => $data['raceDate'],
                'allDay'        => true,
                'classNames'    => ['sp-fc-race', 'sp-fc-race--'.$race->getIntent()->value, $race->isDone() ? 'sp-fc-race--done' : ''],
                'extendedProps' => $data,
            ];
        }

        // Calendrier : on ouvre sur l'année filtrée si ce n'est pas l'année en cours
        $initialDate = ($year !== null && $year !== $currentYear) ? sprintf('%d-01-01', $year) : date('Y-m-d');

        return $this->render('race/index.html.twig', [
            'races'         => $races,
            'racesData'     => $racesData,
            'events'        => $events,
            'undatedRaces'  => $undated,
            'years'         => $years,
            'filterYear'    => $year,
            'filterIntent'  => $intent,
            'view'          => $view,
            'initialDate'   => $initialDate,
            'defaultYear'   => $currentYear,
            'intents'       => RaceIntent::cases(),
        ]);
    }

    #[Route('', name: '_add', methods: ['POST'])]
    public function add(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $race = (new Race())->setUser($user);
        if (!$this->hydrate($race, $request)) {
            $this->addFlash('error', 'races.flash_name_required');

            return $this->redirectBack($request);
        }

        $em->persist($race);
        $em->flush();

        $this->addFlash('success', 'races.flash_added');

        return $this->redirectBack($request);
    }

    #[Route('/{id}/edit', name: '_edit', methods: ['POST'])]
    public function edit(Race $race, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwner($race);

        if (!$this->hydrate($race, $request)) {
            $this->addFlash('error', 'races.flash_name_required');

            return $this->redirectBack($request);
        }

        $em->flush();

        $this->addFlash('success', 'races.flash_updated');

        return $this->redirectBack($request);
    }

    #[Route('/{id}/toggle', name: '_toggle', methods: ['POST'])]
    public function toggle(Race $race, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwner($race);

        $race->setIsDone(!$race->isDone());
        $em->flush();

        return $this->redirectBack($request);
    }

    #[Route('/{id}/intent', name: '_intent', methods: ['POST'])]
    public function changeIntent(Race $race, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwner($race);

        $intent = RaceIntent::tryFrom((string) $request->request->get('intent'));
        if ($intent !== null) {
            $race->setIntent($intent);
            $em->flush();
        }

        return $this->redirectBack($request);
    }

    #[Route('/{id}/delete', name: '_delete', methods: ['POST'])]
    public function delete(Race $race, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessOwner($race);

        $em->remove($race);
        $em->flush();

        $this->addFlash('success', 'races.flash_deleted');

        return $this->redirectBack($request);
    }

    /**
     * Remplit la course depuis le formulaire (ajout ou modification).
     * Retourne false si le nom est vide (rien n'est modifié dans ce cas).
     */
    private function hydrate(Race $race, Request $request): bool
    {
        $post = $request->request;

        $name = trim((string) $post->get('name', ''));
        if ($name === '') {
            return false;
        }

        $race->setName(mb_substr($name, 0, 200));
        $race->setLocation(mb_substr(trim((string) $post->get('location', '')), 0, 200) ?: null);
        $race->setWebsite($this->normalizeUrl((string) $post->get('website', '')));
        $race->setNotes(trim((string) $post->get('notes', '')) ?: null);

        $distance = trim((string) $post->get('distance_km', ''));
        $race->setDistanceKm(is_numeric($distance) ? max(0.0, (float) $distance) : null);

        $elevation = trim((string) $post->get('elevation_gain', ''));
        $race->setElevationGain(is_numeric($elevation) ? max(0, (int) round((float) $elevation)) : null);

        $intent = RaceIntent::tryFrom((string) $post->get('intent'));
        if ($intent !== null) {
            $race->setIntent($intent);
        } elseif ($race->getId() === null) {
            $race->setIntent(RaceIntent::WantToDo);
        }

        // Une date précise, si fournie, fait foi pour l'année
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $post->get('race_date', ''));
        $race->setRaceDate($date ?: null);

        $year = $date ? (int) $date->format('Y') : $post->getInt('year', (int) date('Y'));
        $race->setYear(max(1900, min(2100, $year)));

        return true;
    }

    /**
     * Données d'une course pour le JS (modale d'édition, calendrier).
     *
     * @return array{id: int|null, name: string, location: string|null, distanceKm: float|null, elevationGain: int|null, year: int, raceDate: string|null, intent: string, website: string|null, notes: string|null, isDone: bool}
     */
    private function toArray(Race $race): array
    {
        return [
            'id'            => $race->getId(),
            'name'          => $race->getName(),
            'location'      => $race->getLocation(),
            'distanceKm'    => $race->getDistanceKm(),
            'elevationGain' => $race->getElevationGain(),
            'year'          => $race->getYear(),
            'raceDate'      => $race->getRaceDate()?->format('Y-m-d'),
            'intent'        => $race->getIntent()->value,
            'website'       => $race->getWebsite(),
            'notes'         => $race->getNotes(),
            'isDone'        => $race->isDone(),
        ];
    }

    /** N'accepte que des liens http(s) — le site est rendu dans un href. */
    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            $url = 'https://'.$url;
        }

        return preg_match('#^https?://[^\s]+$#i', $url) && mb_strlen($url) <= 500 ? $url : null;
    }

    private function denyUnlessOwner(Race $race): void
    {
        if ($race->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Revient sur la page des courses en conservant filtres et vue (liste / calendrier).
     */
    private function redirectBack(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer', '');
        $parts   = parse_url($referer);

        if (
            is_array($parts)
            && ($parts['host'] ?? null) === $request->getHost()
            && str_starts_with($parts['path'] ?? '', $this->generateUrl('app_races'))
        ) {
            return $this->redirect($this->generateUrl('app_races').(isset($parts['query']) ? '?'.$parts['query'] : ''));
        }

        return $this->redirectToRoute('app_races');
    }
}
