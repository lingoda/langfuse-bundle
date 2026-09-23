<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Platform;

use Lingoda\AiSdk\Audio\AudioOptionsInterface;
use Lingoda\AiSdk\Exception\ClientException;
use Lingoda\AiSdk\Exception\InvalidArgumentException;
use Lingoda\AiSdk\Exception\ModelNotFoundException;
use Lingoda\AiSdk\Exception\RuntimeException;
use Lingoda\AiSdk\ModelInterface;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Provider\ProviderCollection;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\AiSdk\Result\BinaryResult;
use Lingoda\AiSdk\Result\ResultInterface;
use Lingoda\AiSdk\Result\StreamResult;
use Lingoda\AiSdk\Result\TextResult;
use Lingoda\LangfuseBundle\Prompt\PromptReference;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Decorator that wraps the AI Platform to automatically trace all AI interactions to Langfuse.
 * This is the main integration point between the AI SDK and Langfuse tracing.
 */
final readonly class LangfusePlatformDecorator implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $decorated,
        private TraceManagerInterface $traceManager,
    ) {
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function ask(string|Prompt|Conversation $input, ?string $modelId = null, array $options = []): ResultInterface
    {
        $metadata = [];

        $model = $this->resolveModel($modelId);
        $metadata['provider'] = $model->getProvider()->getName();
        // The requested model makes a failed call a generation too; the model the result reports replaces it
        $metadata['model'] = $model->getId();

        $traceName = $options['trace_name'] ?? 'ai-completion';
        Assert::string($traceName);

        // false keeps model, usage, duration and status but no input, output or error text (e.g. for personal data)
        $recordContent = $options['trace_content'] ?? true;
        Assert::boolean($recordContent);

        $prompt = $options['langfuse_prompt'] ?? null;
        if ($prompt !== null) {
            Assert::isInstanceOf($prompt, PromptReference::class);
            $metadata['langfuse_prompt'] = ['name' => $prompt->name, 'version' => $prompt->version];
        }

        // Group traces into a Langfuse session and attribute them to a user (ids only, never personal data)
        foreach (['langfuse_session_id', 'langfuse_user_id'] as $key) {
            if (isset($options[$key])) {
                Assert::stringNotEmpty($options[$key]);
                $metadata[$key] = $options[$key];
            }
        }

        // Tracing options never reach the provider
        unset($options['trace_name'], $options['trace_content'], $options['langfuse_prompt'], $options['langfuse_session_id'], $options['langfuse_user_id']);

        return $this->traceManager->trace(
            $traceName,
            $metadata,
            $input,
            fn () => $this->decorated->ask($input, $modelId, $options),
            $recordContent
        );
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function textToSpeech(string $input, AudioOptionsInterface $options): BinaryResult
    {
        $result = $this->traceManager->trace(
            'text-to-speech',
            ['input_length' => mb_strlen($input)],
            $input,
            fn () => $this->decorated->textToSpeech($input, $options)
        );
        Assert::isInstanceOf($result, BinaryResult::class);

        return $result;
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function textToSpeechStream(string $input, AudioOptionsInterface $options): StreamResult
    {
        $result = $this->traceManager->trace(
            'text-to-speech-stream',
            ['input_length' => mb_strlen($input)],
            $input,
            fn () => $this->decorated->textToSpeechStream($input, $options)
        );
        Assert::isInstanceOf($result, StreamResult::class);

        return $result;
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function transcribeAudio(string $audioFilePath, AudioOptionsInterface $options): TextResult
    {
        $result = $this->traceManager->trace(
            'audio-transcription',
            ['audio_file' => basename($audioFilePath)],
            $audioFilePath,
            fn () => $this->decorated->transcribeAudio($audioFilePath, $options)
        );
        Assert::isInstanceOf($result, TextResult::class);

        return $result;
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function translateAudio(string $audioFilePath, AudioOptionsInterface $options): TextResult
    {
        $result = $this->traceManager->trace(
            'audio-translation',
            ['audio_file' => basename($audioFilePath)],
            $audioFilePath,
            fn () => $this->decorated->translateAudio($audioFilePath, $options)
        );
        Assert::isInstanceOf($result, TextResult::class);

        return $result;
    }

    public function getProvider(string $name): ProviderInterface
    {
        return $this->decorated->getProvider($name);
    }

    public function getAvailableProviders(): ProviderCollection
    {
        return $this->decorated->getAvailableProviders();
    }

    public function hasProvider(string $name): bool
    {
        return $this->decorated->hasProvider($name);
    }

    public function configureProviderDefaultModel(string $providerName, string $defaultModel): void
    {
        $this->decorated->configureProviderDefaultModel($providerName, $defaultModel);
    }

    public function resolveModel(?string $modelId): ModelInterface
    {
        return $this->decorated->resolveModel($modelId);
    }
}
