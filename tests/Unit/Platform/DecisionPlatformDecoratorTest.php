<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Platform;

use Lingoda\AiSdk\Decision\DecisionPlatformInterface;
use Lingoda\AiSdk\Decision\DecisionResult;
use Lingoda\AiSdk\Decision\Question;
use Lingoda\AiSdk\Provider\TypeSafeProvider;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\LangfuseBundle\Platform\DecisionPlatformDecorator;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DecisionPlatformDecoratorTest extends TestCase
{
    private DecisionPlatformInterface&MockObject $decorated;
    private TraceManagerInterface&MockObject $traceManager;
    private DecisionPlatformDecorator $decorator;

    protected function setUp(): void
    {
        $this->decorated = $this->createMock(DecisionPlatformInterface::class);
        $this->traceManager = $this->createMock(TraceManagerInterface::class);
        $this->decorator = new DecisionPlatformDecorator($this->decorated, $this->traceManager);
    }

    public function testDecisionIsTracedLikeLangfusesTypeSafeIntegration(): void
    {
        $this->decorated->method('getProvider')->willReturn(new TypeSafeProvider());
        $questions = ['refund' => Question::noul('Asks for a refund?')];
        $result = new DecisionResult([], ['model' => 'jev-1.13.0']);

        $this->traceManager->expects(self::once())
            ->method('trace')
            ->with(
                'typesafe-system-one',
                ['provider' => 'TypeSafe', 'model' => 'jev-latest'],
                ['state' => 'Please refund', 'model' => 'jev-latest', 'questions' => ['refund' => $questions['refund']->toArray()]],
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(static fn ($name, $metadata, $input, $callable) => $callable())
        ;
        $this->decorated->expects(self::once())->method('decide')->with('Please refund', $questions, 'jev-latest')->willReturn($result);

        self::assertSame($result, $this->decorator->decide('Please refund', $questions, 'jev-latest'));
    }

    public function testDefaultModelIsTracedWhenNoneIsGiven(): void
    {
        $this->decorated->method('getProvider')->willReturn(new TypeSafeProvider());

        $this->traceManager->expects(self::once())
            ->method('trace')
            ->with(self::anything(), self::anything(), self::callback(static fn (array $input): bool => $input['model'] === 'jev-1.13.0'))
            ->willReturn(new DecisionResult([]))
        ;

        $this->decorator->decide(['text' => 'state'], ['q' => Question::noul('?')]);
    }

    public function testOtherProvidersGetAGenericTraceName(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getId')->willReturn('acme');
        $provider->method('getName')->willReturn('Acme');
        $provider->method('getDefaultModel')->willReturn('acme-1');
        $this->decorated->method('getProvider')->willReturn($provider);

        $this->traceManager->expects(self::once())
            ->method('trace')
            ->with('acme-decision', ['provider' => 'Acme', 'model' => 'acme-1'])
            ->willReturn(new DecisionResult([]))
        ;

        $this->decorator->decide('state', ['q' => Question::noul('?')]);
    }

    public function testFailuresPropagate(): void
    {
        $this->decorated->method('getProvider')->willReturn(new TypeSafeProvider());
        $this->traceManager->method('trace')->willReturnCallback(static fn ($name, $metadata, $input, $callable) => $callable());
        $this->decorated->method('decide')->willThrowException(new \RuntimeException('TypeSafe request failed (HTTP 401)'));

        $this->expectException(\RuntimeException::class);

        $this->decorator->decide('state', ['q' => Question::noul('?')]);
    }

    public function testProviderComesFromTheDecoratedPlatform(): void
    {
        $provider = new TypeSafeProvider();
        $this->decorated->method('getProvider')->willReturn($provider);

        self::assertSame($provider, $this->decorator->getProvider());
    }
}
