<?php

namespace App\Controller\Settings;

use App\Entity\Gear;
use App\Entity\GearType;
use App\Entity\User;
use App\Repository\GearBrandRepository;
use App\Repository\GearRepository;
use App\Security\RateLimitRetry;
use App\Service\Gear\StravaGearImporter;
use App\Service\Input\TextInput;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Matériel de l'utilisateur (Paramètres > Matériel) : ajout, modification, retraite,
 * suppression, rattachement du matériel par défaut aux activités sans matériel et import Strava.
 * Tout est en POST + CSRF ('gear') et revient sur l'onglet Matériel des paramètres. Textes (modèle 120, surnom 60,
 * notes 2000 caractères) : UTF-8 valide et longueur vérifiés, un texte trop long est refusé (jamais tronqué).
 * Import Strava : une fois toutes les 10 minutes par utilisateur (limiteur « strava_gear_import »).
 */
#[Route('/settings/gear', name: 'app_gear_')]
#[IsGranted('ROLE_USER')]
class GearController extends AbstractController
{
    public const CSRF_ID = 'gear';

    public const MODEL_MAX    = 120;
    public const NICKNAME_MAX = 60;
    public const NOTES_MAX    = 2000;

    /** Bornes de saisie, en km. */
    private const MAX_INITIAL_KM  = 100_000;
    private const MAX_LIFESPAN_KM = 1_000_000;

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(
        Request $request,
        EntityManagerInterface $em,
        GearRepository $gearRepo,
        GearBrandRepository $brandRepo,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->csrfOk($request)) {
            return $this->back();
        }

        $type = GearType::tryFrom((string) $request->request->get('type'));
        if ($type === null) {
            $this->addFlash('error', 'gear.flash_invalid_type');

            return $this->back();
        }

        $gear = (new Gear())->setUser($user)->setType($type);
        if (!$this->hydrate($gear, $request, $brandRepo)) {
            return $this->back();
        }

        $em->persist($gear);
        $em->flush();
        if ($gear->isDefault()) {
            $gearRepo->clearOtherDefaults($gear);
        }

        $this->addFlash('success', 'gear.flash_added');

        return $this->back();
    }

    /** Le type n'est pas modifiable (les activités rattachées en dépendent). */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function edit(
        Gear $gear,
        Request $request,
        EntityManagerInterface $em,
        GearRepository $gearRepo,
        GearBrandRepository $brandRepo,
        #[CurrentUser] User $user,
    ): Response {
        $this->denyUnlessOwner($gear, $user);
        if (!$this->csrfOk($request)) {
            return $this->back();
        }

        if (!$this->hydrate($gear, $request, $brandRepo)) {
            return $this->back();
        }

        $em->flush();
        if ($gear->isDefault()) {
            $gearRepo->clearOtherDefaults($gear);
        }

        $this->addFlash('success', 'gear.flash_updated');

        return $this->back();
    }

    /** Met à la retraite (ou réactive) : un matériel retiré n'est plus proposé ni rattaché par défaut. */
    #[Route('/{id}/retire', name: 'retire', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retire(Gear $gear, Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessOwner($gear, $user);
        if (!$this->csrfOk($request)) {
            return $this->back();
        }

        if ($gear->isRetired()) {
            $gear->setRetiredAt(null);
            $flash = 'gear.flash_reactivated';
        } else {
            $gear->setRetiredAt(new \DateTimeImmutable('today'))->setIsDefault(false);
            $flash = 'gear.flash_retired';
        }
        $em->flush();

        $this->addFlash('success', $flash);

        return $this->back();
    }

    /** Supprime le matériel ; les activités restent (gear_id repasse à NULL via la clé étrangère). */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Gear $gear, Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessOwner($gear, $user);
        if (!$this->csrfOk($request)) {
            return $this->back();
        }

        $em->remove($gear);
        $em->flush();

        $this->addFlash('success', 'gear.flash_deleted');

        return $this->back();
    }

    /** Rattache ce matériel aux activités sans matériel des sports de son type (depuis l'achat). */
    #[Route('/{id}/assign-defaults', name: 'assign_defaults', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assignDefaults(
        Gear $gear,
        Request $request,
        GearRepository $gearRepo,
        TranslatorInterface $translator,
        #[CurrentUser] User $user,
    ): Response {
        $this->denyUnlessOwner($gear, $user);
        if (!$this->csrfOk($request)) {
            return $this->back();
        }
        if ($gear->isRetired()) {
            $this->addFlash('error', 'gear.flash_retired_no_assign');

            return $this->back();
        }

        $count = $gearRepo->assignToActivitiesWithoutGear($gear);
        $this->addFlash($count > 0 ? 'success' : 'info', $translator->trans('gear.flash_assigned', [
            '%count%' => $count,
            '%name%'  => $gear->getDisplayName(),
        ]));

        return $this->back();
    }

    /**
     * Importe chaussures et vélos depuis Strava (voir StravaGearImporter). Synchrone : quelques
     * secondes, davantage pour un gros historique (une requête Strava par tranche de 200 activités).
     */
    #[Route('/import-strava', name: 'import_strava', methods: ['POST'])]
    public function importStrava(
        Request $request,
        StravaGearImporter $importer,
        TranslatorInterface $translator,
        LoggerInterface $logger,
        RateLimiterFactoryInterface $stravaGearImportLimiter,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->csrfOk($request)) {
            return $this->back();
        }
        if (!$user->isStravaConnected() || $user->getStravaAccessToken() === null) {
            $this->addFlash('error', 'gear.import_error_not_connected');

            return $this->back();
        }
        $limit = $stravaGearImportLimiter->create('user-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            $this->addFlash('info', $translator->trans('gear.import_cooldown', ['%minutes%' => RateLimitRetry::minutes($limit)]));

            return $this->back();
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        try {
            $result = $importer->import($user, $request->request->getBoolean('replace_links'));
        } catch (\Throwable $e) {
            $logger->error('Strava gear import failed', ['exception' => $e]);
            $this->addFlash('error', 'gear.import_error_unexpected');

            return $this->back();
        }

        if (!$result->isSuccessful()) {
            $this->addFlash('error', 'gear.import_error_'.$result->errors[0]);

            return $this->back();
        }

        if ($result->isEmpty()) {
            $this->addFlash('info', 'gear.import_empty');
        } else {
            $this->addFlash('success', $translator->trans('gear.import_done', [
                '%created%' => $translator->trans('gear.import_created', ['%count%' => $result->created]),
                '%updated%' => $translator->trans('gear.import_updated', ['%count%' => $result->updated]),
                '%linked%'  => $translator->trans('gear.import_linked', ['%count%' => $result->linked]),
            ]));
        }
        if ($result->skipped > 0) {
            $this->addFlash('warning', $translator->trans('gear.import_skipped', ['%count%' => $result->skipped]));
        }
        if ($result->truncated) {
            $this->addFlash('warning', $translator->trans('gear.import_truncated', [
                '%count%' => StravaGearImporter::PAGE_SIZE * StravaGearImporter::MAX_PAGES,
            ]));
        }
        if ($result->profileScopeMissing) {
            $this->addFlash('info', 'gear.import_scope_hint');
        }

        return $this->back();
    }

    /** Remplit le matériel depuis le formulaire ; ajoute un flash et renvoie false si invalide. */
    private function hydrate(Gear $gear, Request $request, GearBrandRepository $brandRepo): bool
    {
        $in = $request->request;

        $brand = $brandRepo->find($in->getInt('brand_id'));
        if ($brand === null || !$brand->supports($gear->getType())) {
            $this->addFlash('error', 'gear.flash_brand_required');

            return false;
        }

        $post  = $in->all();
        $model = TextInput::field($post, 'model', self::MODEL_MAX);
        if ($model === null) {
            $this->addFlash('error', 'gear.flash_model_required');

            return false;
        }
        $nickname = TextInput::field($post, 'nickname', self::NICKNAME_MAX);
        $notes    = TextInput::field($post, 'notes', self::NOTES_MAX, true);
        if ($model === false || $nickname === false || $notes === false) {
            $this->addFlash('error', 'gear.flash_text_invalid');

            return false;
        }

        $purchasedAt = null;
        $rawDate     = trim(TextInput::raw($post, 'purchased_at'));
        if ($rawDate !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            if ($date !== false && $date->format('Y-m-d') === $rawDate) {
                $purchasedAt = $date;
            }
        }

        $initialKm = $this->parseKm(TextInput::raw($post, 'initial_km'));
        $maxKm     = $this->parseKm(TextInput::raw($post, 'max_km'));

        $gear->setBrand($brand)
            ->setModel($model)
            ->setNickname($nickname)
            ->setNotes($notes)
            ->setPurchasedAt($purchasedAt)
            ->setInitialDistanceM((int) round(min($initialKm ?? 0.0, self::MAX_INITIAL_KM) * 1000))
            ->setMaxDistanceM($maxKm !== null && $maxKm > 0 ? (int) round(min($maxKm, self::MAX_LIFESPAN_KM) * 1000) : null)
            // Un matériel à la retraite ne peut pas être celui par défaut
            ->setIsDefault($in->getBoolean('is_default') && !$gear->isRetired());

        return true;
    }

    /** Distance saisie en km ("12,5" accepté) ; null si vide ou invalide, jamais négative. */
    private function parseKm(string $raw): ?float
    {
        $raw = str_replace([',', ' '], ['.', ''], trim($raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return max(0.0, (float) $raw);
    }

    private function csrfOk(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            return true;
        }
        $this->addFlash('error', 'gear.flash_csrf');

        return false;
    }

    private function denyUnlessOwner(Gear $gear, User $user): void
    {
        if ($gear->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function back(): Response
    {
        return $this->redirectToRoute('app_settings', ['tab' => 'gear']);
    }
}
