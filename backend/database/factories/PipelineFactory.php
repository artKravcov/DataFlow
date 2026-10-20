<?php

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'organization_id' => fn (array $attributes): int => Project::query()
                ->findOrFail($attributes['project_id'])
                ->organization_id,
            'name' => fake()->words(3, true),
            'revision' => 0,
            'schema_version' => 1,
            'archived_at' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
    }
}
