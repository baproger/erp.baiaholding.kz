<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $u): bool { return $u->can('document.viewAny'); }
    public function view(User $u, Document $d): bool { return $u->can('document.view'); }
    public function create(User $u): bool { return $u->can('document.create'); }
    public function update(User $u, Document $d): bool { return $u->can('document.update'); }
    public function delete(User $u, Document $d): bool { return $u->can('document.delete'); }
}
