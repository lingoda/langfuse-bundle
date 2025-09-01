<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tracing;

use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Result\ResultInterface;

interface TraceManagerInterface
{
    /**
     * Generic trace method for any AI operation.
     *
     * @template TCallable of ResultInterface
     *
     * @param array<string, mixed> $metadata
     * @param callable(): TCallable $callable The operation to trace
     *
     * @throws \Throwable
     *
     * @return TCallable
     */
    public function trace(string $name, array $metadata, string|Prompt|Conversation $input, callable $callable): ResultInterface;

    /**
     * Check if tracing is enabled.
     */
    public function isEnabled(): bool;
}
