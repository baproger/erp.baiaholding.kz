<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;

class DealPolicy
{
    public function viewAny(User $user): bool { return $user->can('deal.viewAny'); }
    public function view(User $user, Deal $d): bool { return $user->can('deal.view'); }
    public function create(User $user): bool { return $user->can('deal.create'); }
    public function update(User $user, Deal $d): bool { return $user->can('deal.update'); }
    public function delete(User $user, Deal $d): bool { return $user->can('deal.delete'); }
}
