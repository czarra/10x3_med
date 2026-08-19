<?php

namespace App\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class KernelBootTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        self::bootKernel();

        $this->assertTrue(self::$kernel->getContainer()->has('doctrine'));
    }
}
