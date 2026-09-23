<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Client;

use Lingoda\LangfuseBundle\Client\LangfuseConnection;
use PHPUnit\Framework\TestCase;

final class LangfuseConnectionTest extends TestCase
{
    public function testUrlJoinsHostAndPathWithOneSlash(): void
    {
        self::assertSame('https://cloud.langfuse.com/api/public/prompts', (new LangfuseConnection('https://cloud.langfuse.com/', 'pk', 'sk'))->url('/api/public/prompts'));
        self::assertSame('https://cloud.langfuse.com/api/public/prompts', (new LangfuseConnection('https://cloud.langfuse.com', 'pk', 'sk'))->url('api/public/prompts'));
    }

    public function testAuthorizationIsBasicWithBothKeys(): void
    {
        self::assertSame('Basic ' . base64_encode('pk-lf-1:sk-lf-2'), (new LangfuseConnection('https://cloud.langfuse.com', 'pk-lf-1', 'sk-lf-2'))->authorizationHeader());
    }

    public function testSecretKeyIsNotDumped(): void
    {
        $connection = new LangfuseConnection('https://cloud.langfuse.com', 'pk-lf-1', 'sk-lf-SECRET', 12);

        self::assertStringNotContainsString('sk-lf-SECRET', print_r($connection, true));
        self::assertSame(['host' => 'https://cloud.langfuse.com', 'publicKey' => 'pk-lf-1', 'timeout' => 12], $connection->__debugInfo());
    }
}
