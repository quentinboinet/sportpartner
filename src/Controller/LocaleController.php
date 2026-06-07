<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/switch-locale/{locale}', name: 'app_switch_locale', requirements: ['locale' => 'fr|en'])]
    public function switch(string $locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $locale);

        $referer = $request->headers->get('referer');

        return $this->redirect($referer ?: $this->generateUrl('app_dashboard'));
    }
}
