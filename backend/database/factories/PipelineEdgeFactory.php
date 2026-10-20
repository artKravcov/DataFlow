<?php

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\PipelineEdge;
use App\Models\PipelineNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineEdge>
 */
class PipelineEdgeFactory extends Factory
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
            'edge_id' => 'edge-'.fake()->unique()->uuid(),
            'source_node_id' => fn (array $attributes): string => PipelineNode::factory()->create([
                'pipeline_id' => $attributes['pipeline_id'],
                'node_id' => 'source-'.fake()->uuid(),
            ])->node_id,
            'source_port' => 'records',
            'target_node_id' => fn (array $attributes): string => PipelineNode::factory()->create([
                'pipeline_id' => $attributes['pipeline_id'],
                'node_id' => 'target-'.fake()->uuid(),
            ])->node_id,
            'target_port' => 'records',
        ];
    }
}
