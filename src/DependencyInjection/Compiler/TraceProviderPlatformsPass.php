<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\DependencyInjection\Compiler;

use Lingoda\LangfuseBundle\Platform\LangfusePlatformDecorator;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Traces the single-provider platforms ai-bundle registers (openaiPlatform, bedrockPlatform, ...).
 * The main platform is traced through the PlatformInterface decoration and calls clients directly, so nothing is traced twice.
 */
final class TraceProviderPlatformsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(TraceManagerInterface::class)) {
            return;
        }

        foreach ($container->findTaggedServiceIds('ai.platform') as $id => $tags) {
            if (in_array('main', array_column($tags, 'provider'), true)) {
                continue;
            }

            $container->register($id . '.langfuse', LangfusePlatformDecorator::class)
                ->setDecoratedService($id)
                ->setArguments([new Reference($id . '.langfuse.inner'), new Reference(TraceManagerInterface::class)])
            ;
        }
    }
}
