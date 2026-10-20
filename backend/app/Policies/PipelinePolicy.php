<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PipelinePolicy
{
    public function view(User $user, Pipeline $pipeline): Response
    {
        return $user->memberships()->where('organization_id', $pipeline->organization_id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Pipeline $pipeline): Response
    {
        $role = $user->memberships()->where('organization_id', $pipeline->organization_id)->value('role');

        return $role === null ? Response::denyAsNotFound() : (in_array($role, ['owner', 'editor'], true) ? Response::allow() : Response::deny());
    }
}
