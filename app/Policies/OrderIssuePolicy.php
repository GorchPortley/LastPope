<?php

namespace App\Policies;

use App\Models\OrderIssue;
use App\Models\User;

/**
 * Who may do what with a problem report. Only WHO is decided here; whether the issue is in a state that allows the
 * action (window, status, timing) is IssueService's job.
 *
 *   view / reply            the buyer, the seller and every admin
 *   internalNote / decide / recover   admins only (they decide escalated cases in Filament)
 *   makeOffer / confirmReturnReceived   the seller
 *   respondToOffer / addReturnTracking / withdraw   the buyer
 *   escalate                the buyer or the seller
 *
 * Admins have no blanket access on purpose: an admin cannot accept an offer for a buyer, for instance.
 */
class OrderIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformSeller();
    }

    public function view(User $user, OrderIssue $issue): bool
    {
        return $this->participant($user, $issue) || $user->isPlatformSeller();
    }

    public function reply(User $user, OrderIssue $issue): bool
    {
        return $this->view($user, $issue);
    }

    public function internalNote(User $user, OrderIssue $issue): bool
    {
        return $user->isPlatformSeller();
    }

    public function makeOffer(User $user, OrderIssue $issue): bool
    {
        return $issue->isSeller($user);
    }

    public function respondToOffer(User $user, OrderIssue $issue): bool
    {
        return $issue->isBuyer($user);
    }

    public function addReturnTracking(User $user, OrderIssue $issue): bool
    {
        return $issue->isBuyer($user);
    }

    public function confirmReturnReceived(User $user, OrderIssue $issue): bool
    {
        return $issue->isSeller($user);
    }

    public function withdraw(User $user, OrderIssue $issue): bool
    {
        return $issue->isBuyer($user);
    }

    public function escalate(User $user, OrderIssue $issue): bool
    {
        return $this->participant($user, $issue);
    }

    /** Refund fully or partly, or close without action. */
    public function decide(User $user, OrderIssue $issue): bool
    {
        return $user->isPlatformSeller();
    }

    /** Take a lost dispute's money back from the seller. */
    public function recover(User $user, OrderIssue $issue): bool
    {
        return $user->isPlatformSeller();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OrderIssue $issue): bool
    {
        return false;
    }

    public function delete(User $user, OrderIssue $issue): bool
    {
        return false;
    }

    private function participant(User $user, OrderIssue $issue): bool
    {
        return $issue->isBuyer($user) || $issue->isSeller($user);
    }
}
