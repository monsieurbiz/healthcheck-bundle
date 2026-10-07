<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\DependencyInjection;

use MonsieurBiz\HealthcheckBundle\Check\DoCheckInterface;
use MonsieurBiz\HealthcheckBundle\Controller\HealthcheckController;
use MonsieurBiz\HealthcheckBundle\EventListener\HealthcheckListener;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

final class MonsieurBizHealthcheckExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        // First present source wins (array_key_exists, so explicit null does not fall through);
        // absent, empty, or non-string values use the default. Read at container compile time:
        // changing the env requires a container rebuild, it never changes the path mid-process.
        $path = \array_key_exists('HEALTHCHECK_PATH', $_ENV)
            ? $_ENV['HEALTHCHECK_PATH']
            : (\array_key_exists('HEALTHCHECK_PATH', $_SERVER)
                ? $_SERVER['HEALTHCHECK_PATH']
                : getenv('HEALTHCHECK_PATH'));

        $container->setParameter('monsieurbiz.healthcheck.path', (new Route(\is_string($path) && '' !== $path ? $path : '/healthcheck'))->getPath());

        $container->registerForAutoconfiguration(DoCheckInterface::class)
            ->addTag('monsieurbiz.healthcheck');

        $container->register(HealthcheckController::class)
            ->setPublic(true)
            ->addArgument(new TaggedIteratorArgument('monsieurbiz.healthcheck'))
            ->addArgument(new Reference('logger'));

        $container->register(HealthcheckListener::class)
            ->addArgument(new Reference(HealthcheckController::class))
            ->addArgument('%monsieurbiz.healthcheck.path%')
            ->addTag('kernel.event_listener', [
                'event' => KernelEvents::REQUEST,
                'method' => '__invoke',
                'priority' => \PHP_INT_MAX,
            ]);
    }
}
