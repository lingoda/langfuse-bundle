<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Naming;

use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use PHPUnit\Framework\TestCase;

final class PromptIdentifierTest extends TestCase
{
    private PromptIdentifier $identifier;

    protected function setUp(): void
    {
        $this->identifier = new PromptIdentifier();
    }

    public function testBuildIdentifierWithNameOnly(): void
    {
        $result = $this->identifier->buildIdentifier('test-prompt');

        self::assertEquals('test-prompt', $result);
    }

    public function testBuildIdentifierWithNameAndVersion(): void
    {
        $result = $this->identifier->buildIdentifier('test-prompt', 5);

        self::assertEquals('test-prompt_v5', $result);
    }

    public function testBuildIdentifierWithNameAndLabel(): void
    {
        $result = $this->identifier->buildIdentifier('test-prompt', null, 'production');

        $expectedLabelHash = md5('production');
        self::assertEquals("test-prompt_l{$expectedLabelHash}", $result);
    }

    public function testBuildIdentifierWithAllParameters(): void
    {
        $result = $this->identifier->buildIdentifier('test-prompt', 3, 'staging');

        $expectedLabelHash = md5('staging');
        self::assertEquals("test-prompt_v3_l{$expectedLabelHash}", $result);
    }

    public function testBuildIdentifierWithZeroVersion(): void
    {
        $result = $this->identifier->buildIdentifier('test-prompt', 0);

        self::assertEquals('test-prompt_v0', $result);
    }

    public function testBuildIdentifierWithEmptyLabel(): void
    {
        $result = $this->identifier->buildIdentifier('test-prompt', null, '');

        $expectedLabelHash = md5('');
        self::assertEquals("test-prompt_l{$expectedLabelHash}", $result);
    }

    public function testBuildIdentifierWithComplexName(): void
    {
        $result = $this->identifier->buildIdentifier('complex-prompt-name_with-special_chars');

        self::assertEquals('complex-prompt-name_with-special_chars', $result);
    }

    public function testBuildIdentifierWithComplexLabel(): void
    {
        $label = 'complex-label-with-special-chars-and-spaces test';
        $result = $this->identifier->buildIdentifier('test', null, $label);

        $expectedLabelHash = md5($label);
        self::assertEquals("test_l{$expectedLabelHash}", $result);
    }

    public function testBuildIdentifierConsistency(): void
    {
        $name = 'consistent-test';
        $version = 42;
        $label = 'test-label';

        $result1 = $this->identifier->buildIdentifier($name, $version, $label);
        $result2 = $this->identifier->buildIdentifier($name, $version, $label);

        self::assertEquals($result1, $result2);

        $expectedLabelHash = md5($label);
        self::assertEquals("consistent-test_v42_l{$expectedLabelHash}", $result1);
    }

    public function testBuildIdentifierLabelHashingBehavior(): void
    {
        // Different labels should produce different hashes
        $result1 = $this->identifier->buildIdentifier('test', null, 'label1');
        $result2 = $this->identifier->buildIdentifier('test', null, 'label2');

        self::assertNotEquals($result1, $result2);

        // Same labels should produce same hashes
        $result3 = $this->identifier->buildIdentifier('test', null, 'label1');
        self::assertEquals($result1, $result3);
    }

    public function testBuildIdentifierWithUnicodeCharacters(): void
    {
        $result = $this->identifier->buildIdentifier('тест-промпт', 1, 'продакшн');

        $expectedLabelHash = md5('продакшн');
        self::assertEquals("тест-промпт_v1_l{$expectedLabelHash}", $result);
    }
}
