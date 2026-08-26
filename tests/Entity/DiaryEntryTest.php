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

        $tooHigh = $this->buildEntry($user);
        $tooHigh->setGlycemiaMgDl(2001);
        $this->assertGreaterThan(0, count($validator->validate($tooHigh)), 'Expected glycemiaMgDl=2001 to fail validation.');

        $atUpperBoundary = $this->buildEntry($user);
        $atUpperBoundary->setGlycemiaMgDl(2000);
        $this->assertCount(0, $validator->validate($atUpperBoundary));
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

        $tooLow = $this->buildEntry($user);
        $tooLow->setGlycemiaMgDl(100);
        $tooLow->setWw(-0.1);
        $this->assertGreaterThan(0, count($validator->validate($tooLow)));

        $validAtZero = $this->buildEntry($user);
        $validAtZero->setGlycemiaMgDl(100);
        $validAtZero->setWw(0.0);
        $this->assertCount(0, $validator->validate($validAtZero));
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

        $tooLow = $this->buildEntry($user);
        $tooLow->setGlycemiaMgDl(100);
        $tooLow->setInsulinDose(-0.1);
        $this->assertGreaterThan(0, count($validator->validate($tooLow)));

        $validAtZero = $this->buildEntry($user);
        $validAtZero->setGlycemiaMgDl(100);
        $validAtZero->setInsulinDose(0.0);
        $this->assertCount(0, $validator->validate($validAtZero));
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

        $tooShort = $this->buildEntry($user);
        $tooShort->setGlycemiaMgDl(100);
        $tooShort->setActivityIntensity(ActivityIntensity::Light);
        $tooShort->setActivityDurationMinutes(0);
        $this->assertGreaterThan(0, count($validator->validate($tooShort)));

        $validAtOne = $this->buildEntry($user);
        $validAtOne->setGlycemiaMgDl(100);
        $validAtOne->setActivityIntensity(ActivityIntensity::Light);
        $validAtOne->setActivityDurationMinutes(1);
        $this->assertCount(0, $validator->validate($validAtOne));
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
        $entry = new DiaryEntry(
            user: $user,
            glycemiaMgDl: 0,
            measuredAt: new \DateTimeImmutable(),
            insulinWwRatioSnapshot: 1.2,
            baseDoseSnapshot: 13,
        );

        $this->assertSame(0, $entry->getGlycemiaMgDl());
        $this->assertSame(1.2, $entry->getInsulinWwRatioSnapshot());
        $this->assertSame(13, $entry->getBaseDoseSnapshot());
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
        return new DiaryEntry(
            user: $user,
            glycemiaMgDl: 0,
            measuredAt: new \DateTimeImmutable(),
            insulinWwRatioSnapshot: 1.2,
            baseDoseSnapshot: 13,
        );
    }
}
