<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final class Access
{
    public function __construct(private readonly Security $security)
    {
    }

    public function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function isSelf(User $profile): bool
    {
        $current = $this->currentUser();

        return $current !== null && $current->getId() === $profile->getId();
    }

    public function canClose(User $profile): bool
    {
        if ($this->currentUser() === null) {
            return false;
        }
        if ($this->isSelf($profile)) {
            return $this->security->isGranted('wtl_close_own_month');
        }

        return $this->security->isGranted('wtl_close_other_month') && $this->security->isGranted('hours', $profile);
    }

    public function canUnlock(User $profile): bool
    {
        return $this->security->isGranted('wtl_unlock_month') && ($this->isSelf($profile) || $this->security->isGranted('hours', $profile));
    }

    public function canManageAbsences(User $profile): bool
    {
        if ($this->currentUser() === null) {
            return false;
        }
        if ($this->isSelf($profile)) {
            return $this->security->isGranted('wtl_absence_own');
        }

        return $this->security->isGranted('wtl_absence_other') && $this->security->isGranted('hours', $profile);
    }
}
