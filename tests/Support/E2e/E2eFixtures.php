<?php

namespace App\Tests\Support\E2e;

use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Single source of truth for the browser E2E fixture state.
 *
 * Test-support code — lives under tests/, wired into the container only for
 * APP_ENV=e2e (see the `when@e2e` block in config/services.yaml). Shared by the
 * `app:e2e:seed` command and the `POST /__e2e__/reset` endpoint the Playwright
 * global-setup calls.
 *
 * Cleanup is scoped to `@e2e.test` accounts on purpose: the e2e environment
 * points at the same `database-test` Postgres as PHPUnit, so a blanket TRUNCATE
 * would wipe rows a parallel PHPUnit run depends on.
 */
#[When('e2e')]
final class E2eFixtures
{
    public const EMAIL_SUFFIX = '@e2e.test';
    public const PATIENT_WITH_PROFILE_EMAIL = 'patient-with-profile@e2e.test';
    public const PATIENT_WITHOUT_PROFILE_EMAIL = 'patient-fresh@e2e.test';
    public const PATIENT_DASHBOARD_EMAIL = 'patient-dashboard@e2e.test';
    public const PASSWORD = 'E2ePassw0rd!';

    /** Base dose the dashboard scenario patient starts from; the seeded fasting run pushes it to +1. */
    private const DASHBOARD_BASE_DOSE = 14;

    /** Fasting glycemia for each seeded dashboard-scenario morning — 60 mg/dL over target (120). */
    private const DASHBOARD_FASTING_GLYCEMIA = 180;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @return array{seeded: true, users: list<array{email: string, hasProfile: bool}>}
     */
    public function reset(): array
    {
        $this->purgeE2eRows();

        $withProfile = $this->createUser(self::PATIENT_WITH_PROFILE_EMAIL);
        $this->entityManager->persist(new PatientProfile($withProfile, 14, 1.0));

        $this->createUser(self::PATIENT_WITHOUT_PROFILE_EMAIL);

        $dashboard = $this->createUser(self::PATIENT_DASHBOARD_EMAIL);
        $this->entityManager->persist(new PatientProfile($dashboard, self::DASHBOARD_BASE_DOSE, 1.0));

        $this->entityManager->flush();

        $this->seedDashboardBaseDoseScenario();

        return [
            'seeded' => true,
            'users' => [
                ['email' => self::PATIENT_WITH_PROFILE_EMAIL, 'hasProfile' => true],
                ['email' => self::PATIENT_WITHOUT_PROFILE_EMAIL, 'hasProfile' => false],
                ['email' => self::PATIENT_DASHBOARD_EMAIL, 'hasProfile' => true],
            ],
        ];
    }

    /**
     * (Re)seeds the base-dose recommendation scenario for the dedicated dashboard
     * fixture patient: profile base dose back to 14 j., no accepted adjustments, and
     * three consecutive fasting-morning entries at 180 mg/dL — the exact shape
     * BaseDoseSuggestionService needs to surface a 14 -> 15 j. suggestion on /pulpit.
     *
     * Scoped to PATIENT_DASHBOARD_EMAIL only and never drops the user row, so the
     * saved Playwright session stays valid — safe to call before every test and in
     * parallel with specs that drive the other @e2e.test patients.
     *
     * @return array{seeded: true, currentBaseDose: int, suggestedBaseDose: int}
     */
    public function seedDashboardBaseDoseScenario(): array
    {
        $userId = $this->dashboardUserId();
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('DELETE FROM base_dose_adjustment_histories WHERE user_id = :id', ['id' => $userId]);
        $connection->executeStatement('DELETE FROM ratio_adjustment_histories WHERE user_id = :id', ['id' => $userId]);
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = :id', ['id' => $userId]);
        $this->entityManager->clear();

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        $profile = null === $user
            ? null
            : $this->entityManager->getRepository(PatientProfile::class)->findOneBy(['user' => $user]);
        if (null === $user || null === $profile) {
            throw new \RuntimeException('Dashboard fixture user/profile is missing; call reset() first.');
        }

        $profile->setBaseDose(self::DASHBOARD_BASE_DOSE);

        // Three consecutive fasting mornings (07:00), each above BAND_HIGH (145):
        // avg excess over TARGET_GLYCEMIA (120) is 60 -> stepRaw 1.2 -> +1 j. -> 15.
        $morning = new \DateTimeImmutable('today 07:00');
        foreach ([3, 2, 1] as $daysAgo) {
            $this->entityManager->persist(new DiaryEntry(
                user: $user,
                glycemiaMgDl: self::DASHBOARD_FASTING_GLYCEMIA,
                measuredAt: $morning->modify(\sprintf('-%d days', $daysAgo)),
                insulinWwRatioSnapshot: $profile->getInsulinWwRatio(),
                baseDoseSnapshot: self::DASHBOARD_BASE_DOSE,
            ));
        }

        $this->entityManager->flush();

        return [
            'seeded' => true,
            'currentBaseDose' => self::DASHBOARD_BASE_DOSE,
            'suggestedBaseDose' => self::DASHBOARD_BASE_DOSE + 1,
        ];
    }

    private function dashboardUserId(): int
    {
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => self::PATIENT_DASHBOARD_EMAIL]);
        if (null === $user) {
            throw new \RuntimeException('Dashboard fixture user is missing; call reset() first.');
        }

        return (int) $user->getId();
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);

        return $user;
    }

    private function purgeE2eRows(): void
    {
        $connection = $this->entityManager->getConnection();
        $ownedUserIds = "SELECT id FROM users WHERE email LIKE '%".self::EMAIL_SUFFIX."'";

        // Child rows first, then the users themselves (FK order).
        $connection->executeStatement("DELETE FROM ratio_adjustment_histories WHERE user_id IN ($ownedUserIds)");
        $connection->executeStatement("DELETE FROM base_dose_adjustment_histories WHERE user_id IN ($ownedUserIds)");
        $connection->executeStatement("DELETE FROM diary_entries WHERE user_id IN ($ownedUserIds)");
        $connection->executeStatement("DELETE FROM patient_profiles WHERE user_id IN ($ownedUserIds)");
        $connection->executeStatement("DELETE FROM users WHERE email LIKE '%".self::EMAIL_SUFFIX."'");

        $this->entityManager->clear();
    }
}
