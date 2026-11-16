<?php

namespace App\Twig;

use App\Service\Nutrition\ActivityFuelViewBuilder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** activity_fuel(activity) : données de la carte Ravitaillement (null si le sport n'en a pas). */
class ActivityFuelExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActivityFuelViewBuilder $builder,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('activity_fuel', $this->builder->build(...)),
        ];
    }
}
