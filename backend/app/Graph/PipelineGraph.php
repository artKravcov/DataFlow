<?php

namespace App\Graph;

use App\Models\Pipeline;

class PipelineGraph
{
    public function graph(Pipeline $pipeline): array
    {
        $nodes = $pipeline->nodes()->orderBy('id')->get()->map(function ($node) {
            $result = ['id' => $node->node_id, 'type' => $node->type, 'type_version' => $node->type_version, 'position' => $node->position, 'config' => $node->config];
            if ($node->schema_hints !== null) {
                $result['schema_hints'] = $node->schema_hints;
            }

            return $result;
        })->all();
        $edges = $pipeline->edges()->orderBy('id')->get()->map(fn ($edge) => ['id' => $edge->edge_id, 'source' => $edge->source_node_id, 'source_port' => $edge->source_port, 'target' => $edge->target_node_id, 'target_port' => $edge->target_port])->all();

        return ['schema_version' => $pipeline->schema_version, 'nodes' => $nodes, 'edges' => $edges];
    }
}
