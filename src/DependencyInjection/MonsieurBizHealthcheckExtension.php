<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\DependencyInjection;

use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class MonsieurBizHealthcheckExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(HealthcheckController::class)
            ->setPublic(true)
            ->addArgument(new Reference('event_dispatcher'));
    }
}
