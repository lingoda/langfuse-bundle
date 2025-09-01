<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tracing;

use Lingoda\AiSdk\Result\Usage;
use Lingoda\LangfuseBundle\PhpStan\Types;

/**
 * Interface for trace flushing services.
 * Implementations can be synchronous or asynchronous.
 *
 * @phpstan-import-type TraceData from Types
 */
interface TraceFlusherInterface
{
    /**
     * Flush trace data to Langfuse.
     *
     * @param TraceData $traceData
     */
    public function flush(array $traceData, ?Usage $usage = null): void;
}
