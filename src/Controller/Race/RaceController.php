<?php
namespace App\Controller\Race;

use App\Entity\Race;
use App\Entity\RaceIntent;
use App\Repository\RaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/courses', name: 'app_races')]
#[IsGranted('ROLE_USER')]
class RaceController extends AbstractController
{
    #[Route('', name: '', methods: ['GET'])]
    public function index(RaceRepository $repo, Request $request): Response
    {
        $user = $this->getUser();
        $year   = $request->query->getInt('year', 0) ?: null;
        $intent = $request->query->get('intent');

        $races = $repo->findByUser($user, $year, $intent);
        $years = $repo->getYearsForUser($user);

        // Ensure current year is always in the year list for the filter
        $currentYear = (int) date('Y');
        if (!in_array($currentYear, $years, true)) {
            $years[] = $currentYear;
            sort($years);
        }

        return $this->render('race/index.html.twig', [
            'races'         => $races,
            'years'         => $years,
            'filterYear'    => $year,
            'filterIntent'  => $intent,
            'defaultYear'   => $currentYear,
        ]);
    }

    #[Route('', name: '_add', methods: ['POST'])]
    public function add(Request $request, EntityManagerInterface $em): Response
    {
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'races.flash_name_required');
            return $this->redirectToRoute('app_races');
        }

        $race = new Race();
        $race->setUser($this->getUser());
        $race->setName($name);
        $race->setLocation(trim((string) $request->request->get('location', '')) ?: null);
        $race->setDistanceKm($request->request->get('distance_km') !== '' ? (float) $request->request->get('distance_km') : null);
        $race->setYear((int) $request->request->get('year', date('Y')));
        $race->setIntent(RaceIntent::from($request->request->get('intent', RaceIntent::WantToDo->value)));
        $race->setNotes(trim((string) $request->request->get('notes', '')) ?: null);
        $race->setWebsite(trim((string) $request->request->get('website', '')) ?: null);

        $em->persist($race);
        $em->flush();

        $this->addFlash('success', 'races.flash_added');
        return $this->redirectToRoute('app_races');
    }

    #[Route('/{id}/toggle', name: '_toggle', methods: ['POST'])]
    public function toggle(Race $race, EntityManagerInterface $em): Response
    {
        if ($race->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $race->setIsDone(!$race->isDone());
        $em->flush();

        return $this->redirectToRoute('app_races');
    }

    #[Route('/{id}/delete', name: '_delete', methods: ['POST'])]
    public function delete(Race $race, EntityManagerInterface $em): Response
    {
        if ($race->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($race);
        $em->flush();

        $this->addFlash('success', 'races.flash_deleted');
        return $this->redirectToRoute('app_races');
    }
}
