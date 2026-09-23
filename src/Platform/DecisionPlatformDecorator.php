<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Platform;

use Lingoda\AiSdk\Decision\DecisionPlatformInterface;
use Lingoda\AiSdk\Decision\DecisionResult;
use Lingoda\AiSdk\Decision\Question;
use Lingoda\AiSdk\Enum\AIProvider;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Traces decisions the way Langfuse's own TypeSafe integration does: a generation named typesafe-system-one,
 * the request {state, model, questions} as input and the typed answers as output.
 */
final readonly class DecisionPlatformDecorator implements DecisionPlatformInterface
{
    public function __construct(
        private DecisionPlatformInterface $decorated,
        private TraceManagerInterface $traceManager,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function decide(string|array $state, array $questions, ?string $model = null): DecisionResult
    {
        $provider = $this->decorated->getProvider();
        $requestedModel = $model ?? $provider->getDefaultModel();

        $result = $this->traceManager->trace(
            $provider->is(AIProvider::TYPESAFE) ? 'typesafe-system-one' : $provider->getId() . '-decision',
            // The requested model makes a failed decision a generation too; the model TypeSafe reports replaces it
            ['provider' => $provider->getName(), 'model' => $requestedModel],
            [
                'state' => $state,
                'model' => $requestedModel,
                'questions' => array_map(static fn (Question $question): array => $question->toArray(), $questions),
            ],
            fn () => $this->decorated->decide($state, $questions, $model)
        );
        Assert::isInstanceOf($result, DecisionResult::class);

        return $result;
    }

    public function getProvider(): ProviderInterface
    {
        return $this->decorated->getProvider();
    }
}
