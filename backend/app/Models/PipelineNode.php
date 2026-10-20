<?php

namespace App\Models;

use Database\Factories\PipelineNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['node_id', 'type', 'type_version', 'config', 'position', 'schema_hints'])]
class PipelineNode extends Model
{
    /** @use HasFactory<PipelineNodeFactory> */
    use HasFactory;

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'array',
            'schema_hints' => 'array',
            'type_version' => 'integer',
        ];
    }
}
