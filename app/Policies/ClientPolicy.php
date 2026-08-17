<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool { return $user->can('client.viewAny'); }
    public function view(User $user, Client $c): bool { return $user->can('client.view'); }
    public function create(User $user): bool { return $user->can('client.create'); }
    // Права не зависят от конкретного клиента — контроллер спрашивает
    // can('update', Client::class) для всей страницы, экземпляр необязателен.
    public function update(User $user, ?Client $c = null): bool { return $user->can('client.update'); }
    public function delete(User $user, ?Client $c = null): bool { return $user->can('client.delete'); }
}
