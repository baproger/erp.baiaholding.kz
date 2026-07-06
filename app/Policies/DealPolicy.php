<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;

class DealPolicy
{
    public function viewAny(User $user): bool { return $user->can('deal.viewAny'); }
    public function view(User $user, Deal $d): bool { return $user->can('deal.view') && $this->ownsOrLeads($user, $d); }
    public function create(User $user): bool { return $user->can('deal.create'); }
    public function update(User $user, Deal $d): bool { return $this->ownsOrLeads($user, $d) && ($user->can('deal.update') || $d->responsible_user_id === $user->id); }
    public function delete(User $user, Deal $d): bool { return $user->can('deal.delete') && $this->ownsOrLeads($user, $d); }

    /** Leadership sees everything; a manager is limited to deals they are responsible for. */
    private function ownsOrLeads(User $user, Deal $d): bool
    {
        return $user->hasAnyRole(['admin', 'director', 'financist']) || $d->responsible_user_id === $user->id;
    }
}
