<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Testing\Conformance\ReadmeAssertions;
use IndexNowKit\Verify\VerifyConfig;
use PHPUnit\Framework\TestCase;

/**
 * The "Notes for AI assistants" section of the README (EN and RU): present, with a complete snippet, naming only
 * commands and configuration keys that exist (spec 17 §3.1).
 */
final class ReadmeAiNotesTest extends TestCase
{
    public function testTheNotesForAiAssistantsAreConsistentWithTheCode(): void
    {
        ReadmeAssertions::assertAiNotes(\dirname(__DIR__, 2), ['indexnow:check', 'indexnow/check', 'indexnow:sitemap'], VerifyConfig::OPTIONS);
    }
}
