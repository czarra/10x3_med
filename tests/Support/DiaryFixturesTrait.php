<?php

namespace App\Tests\Support;

use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

trait DiaryFixturesTrait
{
    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(EntityManagerInterface $entityManager, string $emailPrefix = 'diary'): User
    {
        $user = new User();
        $user->setEmail(sprintf('%s-%s@example.test', $emailPrefix, uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    protected function createProfile(EntityManagerInterface $entityManager, User $user, int $baseDose, float $insulinWwRatio): PatientProfile
    {
        $profile = new PatientProfile($user, $baseDose, $insulinWwRatio);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $profile;
    }

    protected function createEntry(EntityManagerInterface $entityManager, User $user, int $glycemiaMgDl, \DateTimeImmutable $measuredAt, ?\DateTimeImmutable $createdAt = null): DiaryEntry
    {
        $entry = new DiaryEntry(
            user: $user,
            glycemiaMgDl: $glycemiaMgDl,
            measuredAt: $measuredAt,
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );

        if (null !== $createdAt) {
            $this->backdateCreatedAt($entry, $createdAt);
        }

        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function backdateCreatedAt(DiaryEntry $entry, \DateTimeImmutable $createdAt): void
    {
        (new \ReflectionProperty(DiaryEntry::class, 'createdAt'))->setValue($entry, $createdAt);
    }

    protected function cleanupUser(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ratio_adjustment_histories WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM base_dose_adjustment_histories WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}
