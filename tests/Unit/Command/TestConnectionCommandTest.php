<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Command;

use Lingoda\LangfuseBundle\Client\LangfuseConnection;
use Lingoda\LangfuseBundle\Command\TestConnectionCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TestConnectionCommandTest extends TestCase
{
    private LangfuseConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new LangfuseConnection('https://cloud.langfuse.com', 'pk-test', 'sk-test', 12);
    }

    public function testCommandNameAndDescription(): void
    {
        $command = new TestConnectionCommand($this->connection, new MockHttpClient());

        self::assertSame('langfuse:test-connection', $command->getName());
        self::assertSame('Test connection to Langfuse API', $command->getDescription());
    }

    public function testSuccessfulConnectionIsAnAuthenticatedRead(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('{"data": []}', ['http_code' => 200]);
        });

        $tester = new CommandTester(new TestConnectionCommand($this->connection, $httpClient));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Attempting to connect to https://cloud.langfuse.com', $tester->getDisplay());
        self::assertStringContainsString('Successfully connected to Langfuse API!', $tester->getDisplay());

        self::assertCount(1, $requests);
        [$method, $url, $options] = $requests[0];
        self::assertSame('GET', $method);
        self::assertSame('https://cloud.langfuse.com/api/public/projects', $url);
        self::assertContains('Authorization: Basic cGstdGVzdDpzay10ZXN0', $options['headers']);
        self::assertSame(12.0, (float) $options['timeout']);
    }

    public function testRejectedKeysFail(): void
    {
        $tester = new CommandTester(new TestConnectionCommand($this->connection, new MockHttpClient(new MockResponse('', ['http_code' => 401]))));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Failed to connect to Langfuse API (HTTP 401)', $tester->getDisplay());
    }

    public function testTransportErrorFails(): void
    {
        $httpClient = new MockHttpClient(static fn () => throw new TransportException('Could not resolve host'));
        $tester = new CommandTester(new TestConnectionCommand($this->connection, $httpClient));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Connection failed: Could not resolve host', $tester->getDisplay());
        self::assertStringNotContainsString('Exception:', $tester->getDisplay());
    }

    public function testVerboseOutputNamesTheException(): void
    {
        $httpClient = new MockHttpClient(static fn () => throw new TransportException('Could not resolve host'));
        $tester = new CommandTester(new TestConnectionCommand($this->connection, $httpClient));

        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringContainsString('Exception: ' . TransportException::class, $tester->getDisplay());
    }

    public function testDefaultsToItsOwnHttpClient(): void
    {
        self::assertInstanceOf(TestConnectionCommand::class, new TestConnectionCommand($this->connection));
    }
}
