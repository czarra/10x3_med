<?php

namespace App\Tests\Security;

use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Repository\BaseDoseAdjustmentHistoryRepository;
use App\Repository\RatioAdjustmentHistoryRepository;
use App\Security\DiaryEntryVoter;
use App\Service\Editability\DiaryEntryEditabilityService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class DiaryEntryVoterTest extends TestCase
{
    public function testOwnershipMismatchDenies(): void
    {
        $owner = $this->userWithId(1);
        $otherUser = $this->userWithId(2);
        $entry = $this->createEntry($owner);

        $voter = new DiaryEntryVoter($this->editabilityService());
        $token = $this->tokenFor($otherUser);

        $result = $voter->vote($token, $entry, [DiaryEntryVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testOwnedButIneligibleDenies(): void
    {
        $owner = $this->userWithId(1);
        $entry = $this->createEntry($owner);
        $this->backdateCreatedAt($entry, new \DateTimeImmutable('-25 hours'));

        $voter = new DiaryEntryVoter($this->editabilityService());
        $token = $this->tokenFor($owner);

        $result = $voter->vote($token, $entry, [DiaryEntryVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testOwnedAndEligibleGrants(): void
    {
        $owner = $this->userWithId(1);
        $entry = $this->createEntry($owner);

        $voter = new DiaryEntryVoter($this->editabilityService());
        $token = $this->tokenFor($owner);

        $result = $voter->vote($token, $entry, [DiaryEntryVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testNonDiaryEntrySubjectAbstains(): void
    {
        $owner = $this->userWithId(1);

        $voter = new DiaryEntryVoter($this->editabilityService());
        $token = $this->tokenFor($owner);

        $result = $voter->vote($token, new \stdClass(), [DiaryEntryVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        $owner = $this->userWithId(1);
        $entry = $this->createEntry($owner);

        $voter = new DiaryEntryVoter($this->editabilityService());
        $token = $this->tokenFor($owner);

        $result = $voter->vote($token, $entry, ['SOME_OTHER_ATTRIBUTE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function createEntry(User $owner): DiaryEntry
    {
        return new DiaryEntry(
            user: $owner,
            glycemiaMgDl: 100,
            measuredAt: new \DateTimeImmutable(),
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );
    }

    private function backdateCreatedAt(DiaryEntry $entry, \DateTimeImmutable $createdAt): void
    {
        (new \ReflectionProperty(DiaryEntry::class, 'createdAt'))->setValue($entry, $createdAt);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    /**
     * Real service, no adjustment history anywhere (stub repositories return null) —
     * isolates the Voter's own ownership/dispatch logic from the editability rule,
     * which has its own dedicated boundary-matrix coverage in DiaryEntryEditabilityServiceTest.
     */
    private function editabilityService(): DiaryEntryEditabilityService
    {
        $ratioRepository = $this->createStub(RatioAdjustmentHistoryRepository::class);
        $ratioRepository->method('findLatestByUser')->willReturn(null);

        $baseDoseRepository = $this->createStub(BaseDoseAdjustmentHistoryRepository::class);
        $baseDoseRepository->method('findLatestByUser')->willReturn(null);

        return new DiaryEntryEditabilityService($ratioRepository, $baseDoseRepository);
    }
}
