<?php

namespace App\Security;

use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Service\Editability\DiaryEntryEditabilityService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'DIARY_ENTRY_EDIT'|'DIARY_ENTRY_DELETE', DiaryEntry>
 */
final class DiaryEntryVoter extends Voter
{
    public const EDIT = 'DIARY_ENTRY_EDIT';
    public const DELETE = 'DIARY_ENTRY_DELETE';

    public function __construct(
        private readonly DiaryEntryEditabilityService $editabilityService,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof DiaryEntry;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        /** @var DiaryEntry $subject */
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($subject->getUser()->getId() !== $user->getId()) {
            return false;
        }

        return $this->editabilityService->isEditable($subject, new \DateTimeImmutable());
    }
}
