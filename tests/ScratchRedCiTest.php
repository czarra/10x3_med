<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Scratch test — deliberately fails to verify that a red CI blocks the deploy.
 * Do NOT merge. Delete with the scratch/ci-red-check branch.
 */
class ScratchRedCiTest extends TestCase
{
    public function testDeliberateFailure(): void
    {
        self::assertTrue(false, 'intentional failure for the 3.6 red-CI check');
    }
}
