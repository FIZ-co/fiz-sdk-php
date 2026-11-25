<?php

declare(strict_types=1);

namespace Fiz\Tests\Unit;

use Fiz\Fiz;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    /** Packagist takes the version from the git tag; the newest CHANGELOG entry must name the same one. */
    public function testVersionMatchesTheNewestChangelogEntry(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        self::assertSame(1, preg_match('/^## (\d+\.\d+\.\d+)/m', $changelog, $match));
        self::assertSame($match[1], Fiz::VERSION);
    }
}
