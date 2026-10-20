<?php

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\PipelineNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineNode>
 */
class PipelineNodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'node_id' => 'node-'.fake()->unique()->uuid(),
            'type' => 'transform.filter',
            'type_version' => 1,
            'config' => [
                'field' => 'status',
                'operator' => 'equals',
                'value' => 'paid',
            ],
            'position' => ['x' => 0, 'y' => 0],
            'schema_hints' => null,
        ];
    }
}
