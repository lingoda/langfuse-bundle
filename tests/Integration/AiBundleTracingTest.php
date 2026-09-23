<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Integration;

use Lingoda\AiBundle\LingodaAiBundle;
use Lingoda\LangfuseBundle\LingodaLangfuseBundle;
use Lingoda\LangfuseBundle\Platform\DecisionPlatformDecorator;
use Lingoda\LangfuseBundle\Platform\LangfusePlatformDecorator;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots ai-bundle and this bundle together: every platform an app can inject is traced.
 */
final class AiBundleTracingTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): TestKernel
    {
        $kernel = parent::createKernel($options);
        \assert($kernel instanceof TestKernel);

        $kernel->addTestBundle(LingodaAiBundle::class);
        $kernel->addTestBundle(LingodaLangfuseBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }

    public function testMainProviderAndDecisionPlatformsAreTraced(): void
    {
        self::bootKernel(['config' => static fn (TestKernel $kernel) => $kernel->addTestConfig(__DIR__ . '/config/ai_bundle_test.yaml')]);
        $container = self::getContainer();

        self::assertInstanceOf(LangfusePlatformDecorator::class, $container->get('app.platform'));
        self::assertInstanceOf(LangfusePlatformDecorator::class, $container->get('app.openai_platform'));
        self::assertInstanceOf(DecisionPlatformDecorator::class, $container->get('app.decisions'));
    }

    public function testWithoutTypeSafeTheDecisionDecoratorIsDropped(): void
    {
        // Also the minimal langfuse config: no tracing and no prompts block
        self::bootKernel(['config' => static fn (TestKernel $kernel) => $kernel->addTestConfig(__DIR__ . '/config/ai_bundle_without_typesafe_test.yaml')]);

        self::assertInstanceOf(LangfusePlatformDecorator::class, self::getContainer()->get('app.platform'));
        self::assertFalse(self::getContainer()->has(DecisionPlatformDecorator::class));
    }
}
