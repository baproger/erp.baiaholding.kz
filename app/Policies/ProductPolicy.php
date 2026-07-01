<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool { return $user->can('product.viewAny'); }
    public function view(User $user, Product $p): bool { return $user->can('product.view'); }
    public function create(User $user): bool { return $user->can('product.create'); }
    public function update(User $user, Product $p): bool { return $user->can('product.update'); }
    public function delete(User $user, Product $p): bool { return $user->can('product.delete'); }
}
