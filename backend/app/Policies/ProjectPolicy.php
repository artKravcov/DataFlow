<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use App\Models\Organization;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): Response
    {
        if ($user->memberships()->where('organization_id', $project->organization_id)->exists()) {
            return Response::allow();
        }
        return Response::denyAsNotFound();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Organization $organization): Response
    {
        $role = $user->memberships()
            ->where('organization_id', $organization->id)
            ->value('role');

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if (in_array($role, ['owner', 'editor'], true)) {
            return Response::allow();
        }

        return Response::deny();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): Response
    {
        $role = $user->memberships()
            ->where('organization_id', $project->organization_id)
            ->value('role');

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if (in_array($role, ['owner', 'editor'], true)) {
            return Response::allow();
        }

        return Response::deny();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return false;
    }

    public function archive(User $user, Project $project): Response
    {
        return $this->update($user, $project);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }
}
