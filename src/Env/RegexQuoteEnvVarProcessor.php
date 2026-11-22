<?php

namespace App\Env;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Processeur %env(regex_quote:...)% : échappe une valeur pour l'insérer telle quelle dans une expression régulière
 * (preg_quote). Sert aux hôtes de confiance de la prod (framework.trusted_hosts, config/packages/framework.yaml) :
 * '^%env(regex_quote:key:host:url:APP_URL)%$' n'accepte que l'hôte exact d'APP_URL, sans que ses points ne
 * deviennent des jokers. Valeur vide refusée : mieux vaut une erreur au démarrage qu'un motif qui n'accepte rien.
 */
final class RegexQuoteEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $value = $getEnv($name);
        if (!\is_string($value) || $value === '') {
            throw new RuntimeException(\sprintf('Env var "%s" must be a non-empty string to be used with "%s:".', $name, $prefix));
        }

        return preg_quote($value);
    }

    public static function getProvidedTypes(): array
    {
        return ['regex_quote' => 'string'];
    }
}
