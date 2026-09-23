<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\DependencyInjection\Compiler;

use Lingoda\LangfuseBundle\DependencyInjection\Compiler\TraceProviderPlatformsPass;
use Lingoda\LangfuseBundle\Platform\LangfusePlatformDecorator;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class TraceProviderPlatformsPassTest extends TestCase
{
    public function testProviderPlatformsAreDecoratedAndTheMainPlatformIsNot(): void
    {
        $container = new ContainerBuilder();
        $container->register(TraceManagerInterface::class);
        $container->register('lingoda_ai.platform')->addTag('ai.platform', ['provider' => 'main', 'multi_provider' => true]);
        $container->register('openaiPlatform')->addTag('ai.platform', ['provider' => 'openai']);
        $container->register('bedrockPlatform')->addTag('ai.platform', ['provider' => 'bedrock']);

        (new TraceProviderPlatformsPass())->process($container);

        foreach (['openaiPlatform', 'bedrockPlatform'] as $id) {
            $decorator = $container->getDefinition($id . '.langfuse');
            self::assertSame(LangfusePlatformDecorator::class, $decorator->getClass());
            self::assertSame([$id, null, 0], $decorator->getDecoratedService());
            self::assertEquals([new Reference($id . '.langfuse.inner'), new Reference(TraceManagerInterface::class)], $decorator->getArguments());
        }
        self::assertFalse($container->has('lingoda_ai.platform.langfuse'));
    }

    public function testNothingIsDecoratedWithoutTheTraceManager(): void
    {
        $container = new ContainerBuilder();
        $container->register('openaiPlatform')->addTag('ai.platform', ['provider' => 'openai']);

        (new TraceProviderPlatformsPass())->process($container);

        self::assertFalse($container->has('openaiPlatform.langfuse'));
    }
}
