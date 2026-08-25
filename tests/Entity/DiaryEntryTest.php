<?php

namespace App\Tests\Entity;

use App\Entity\ActivityIntensity;
use App\Entity\DiaryEntry;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DiaryEntryTest extends KernelTestCase
{
    public function testGlycemiaBoundary(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $atBoundary = $this->buildEntry($user);
        $atBoundary->setGlycemiaMgDl(20);
        $this->assertGreaterThan(0, count($validator->validate($atBoundary)), 'Expected glycemiaMgDl=20 to fail validation.');

        $abovBoundary = $this->buildEntry($user);
        $abovBoundary->setGlycemiaMgDl(21);
        $this->assertCount(0, $validator->validate($abovBoundary));
    }

    public function testFutureMeasuredAtFailsValidation(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $entry = $this->buildEntry($user);
        $entry->setGlycemiaMgDl(100);
        $entry->setMeasuredAt((new \DateTimeImmutable())->modify('+1 hour'));

        $this->assertGreaterThan(0, count($validator->validate($entry)), 'Expected a future measuredAt to fail validation.');
    }

    public function testPastMeasuredAtPassesValidation(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $entry = $this->buildEntry($user);
        $entry->setGlycemiaMgDl(100);
        $entry->setMeasuredAt((new \DateTimeImmutable())->modify('-1 hour'));

        $this->assertCount(0, $validator->validate($entry));
    }

    public function testWwRangeBoundaries(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $tooHigh = $this->buildEntry($user);
        $tooHigh->setGlycemiaMgDl(100);
        $tooHigh->setWw(20.1);
        $this->assertGreaterThan(0, count($validator->validate($tooHigh)));

        $valid = $this->buildEntry($user);
        $valid->setGlycemiaMgDl(100);
        $valid->setWw(20.0);
        $this->assertCount(0, $validator->validate($valid));
    }

    public function testInsulinDoseRangeBoundaries(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $tooHigh = $this->buildEntry($user);
        $tooHigh->setGlycemiaMgDl(100);
        $tooHigh->setInsulinDose(50.1);
        $this->assertGreaterThan(0, count($validator->validate($tooHigh)));

        $valid = $this->buildEntry($user);
        $valid->setGlycemiaMgDl(100);
        $valid->setInsulinDose(50.0);
        $this->assertCount(0, $validator->validate($valid));
    }

    public function testActivityDurationRangeBoundaries(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $tooLong = $this->buildEntry($user);
        $tooLong->setGlycemiaMgDl(100);
        $tooLong->setActivityIntensity(ActivityIntensity::Light);
        $tooLong->setActivityDurationMinutes(301);
        $this->assertGreaterThan(0, count($validator->validate($tooLong)));

        $valid = $this->buildEntry($user);
        $valid->setGlycemiaMgDl(100);
        $valid->setActivityIntensity(ActivityIntensity::Light);
        $valid->setActivityDurationMinutes(300);
        $this->assertCount(0, $validator->validate($valid));
    }

    public function testActivityIntensityWithoutDurationFailsValidation(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $entry = $this->buildEntry($user);
        $entry->setGlycemiaMgDl(100);
        $entry->setActivityIntensity(ActivityIntensity::Medium);

        $violations = $validator->validate($entry);
        $this->assertGreaterThan(0, count($violations));
        $this->assertSame('activityDurationMinutes', $violations[0]->getPropertyPath());
    }

    public function testActivityDurationWithoutIntensityFailsValidation(): void
    {
        $validator = $this->validator();
        $user = $this->buildUser();

        $entry = $this->buildEntry($user);
        $entry->setGlycemiaMgDl(100);
        $entry->setActivityDurationMinutes(30);

        $violations = $validator->validate($entry);
        $this->assertGreaterThan(0, count($violations));
        $this->assertSame('activityIntensity', $violations[0]->getPropertyPath());
    }

    public function testConstructorDefaultsAreDeliberatelyInvalidUntilFormFillsThemIn(): void
    {
        $user = $this->buildUser();
        $entry = new DiaryEntry($user, 1.2, 12.5);

        $this->assertSame(0, $entry->getGlycemiaMgDl());
        $this->assertSame(1.2, $entry->getInsulinWwRatioSnapshot());
        $this->assertSame(12.5, $entry->getBaseDoseSnapshot());
        $this->assertSame($user, $entry->getUser());
    }

    private function validator(): ValidatorInterface
    {
        self::bootKernel();

        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);

        return $validator;
    }

    private function buildUser(): User
    {
        $user = new User();
        $user->setEmail(sprintf('diary-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');

        return $user;
    }

    private function buildEntry(User $user): DiaryEntry
    {
        return new DiaryEntry($user, 1.2, 12.5);
    }
}
