<?php

namespace App\Models;

use Database\Factories\PipelineEdgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['edge_id', 'source_node_id', 'source_port', 'target_node_id', 'target_port'])]
class PipelineEdge extends Model
{
    /** @use HasFactory<PipelineEdgeFactory> */
    use HasFactory;

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
