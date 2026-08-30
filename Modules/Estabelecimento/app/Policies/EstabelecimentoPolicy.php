<?php

namespace Modules\Estabelecimento\Policies;

use Modules\Usuario\Models\User;

class EstabelecimentoPolicy
{
    public function view(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }

    public function update(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }

    public function updateLogotipo(User $user): bool
    {
        return $user->can('gerir-estabelecimento');
    }
}
