<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Naming;

/**
 * Creates consistent prompt identifiers for use across caching and storage systems.
 * Handles name/version/label combinations with consistent formatting.
 */
final readonly class PromptIdentifier
{
    /**
     * Build base identifier from name, version, and label.
     * Used as building block for cache keys and filenames.
     */
    public function buildIdentifier(string $name, ?int $version = null, ?string $label = null): string
    {
        $identifier = $name;

        if ($version !== null) {
            $identifier .= sprintf('_v%s', $version);
        }

        if ($label !== null) {
            $identifier .= sprintf('_l%s', md5($label));
        }

        return $identifier;
    }
}
