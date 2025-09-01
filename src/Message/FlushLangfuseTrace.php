<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Message;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\PhpStan\Types;

/**
 * Message for asynchronous Langfuse trace flushing.
 *
 * @phpstan-import-type TraceData from Types
 */
final readonly class FlushLangfuseTrace
{
    /**
     * @param TraceData $traceData
     */
    public function __construct(
        private array $traceData,
        private ?Usage $usage = null,
    ) {
    }

    /**
     * @return TraceData
     */
    public function getTraceData(): array
    {
        return $this->traceData;
    }

    public function getUsage(): ?Usage
    {
        return $this->usage;
    }
}
