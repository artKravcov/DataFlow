<?php

namespace App\Graph;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GraphInput
{
    public function __construct(private GraphValidator $graphValidator, private AssetResolver $assets) {}

    public function validate(array $graph, int $organizationId, bool $complete = false): array
    {
        $v = Validator::make(['graph' => $graph], [
            'graph' => 'required|array:schema_version,nodes,edges',
            'graph.schema_version' => 'required|integer|in:1',
            'graph.nodes' => 'present|array|list|max:50',
            'graph.nodes.*' => 'required|array:id,type,type_version,position,config,schema_hints',
            'graph.nodes.*.id' => 'required|string|max:128|distinct:strict',
            'graph.nodes.*.type' => 'required|string|max:128',
            'graph.nodes.*.type_version' => 'required|integer|min:1|max:65535',
            'graph.nodes.*.position' => 'required|array:x,y',
            'graph.nodes.*.position.x' => 'required|numeric',
            'graph.nodes.*.position.y' => 'required|numeric',
            'graph.nodes.*.config' => 'present|array',
            'graph.nodes.*.schema_hints' => 'sometimes|array|list|max:256',
            'graph.nodes.*.schema_hints.*' => 'required|array:name,type,nullable,metadata',
            'graph.nodes.*.schema_hints.*.name' => 'required|string|max:128',
            'graph.nodes.*.schema_hints.*.type' => ['required', Rule::in(['string', 'integer', 'float', 'decimal', 'boolean', 'date', 'datetime', 'json', 'unknown'])],
            'graph.nodes.*.schema_hints.*.nullable' => 'required|boolean',
            'graph.nodes.*.schema_hints.*.metadata' => 'sometimes|array:label',
            'graph.nodes.*.schema_hints.*.metadata.label' => 'sometimes|string|max:128',
            'graph.edges' => 'present|array|list|max:100',
            'graph.edges.*' => 'required|array:id,source,source_port,target,target_port',
            'graph.edges.*.id' => 'required|string|max:128|distinct:strict',
            'graph.edges.*.source' => 'required|string|max:128',
            'graph.edges.*.source_port' => 'required|string|max:128',
            'graph.edges.*.target' => 'required|string|max:128',
            'graph.edges.*.target_port' => 'required|string|max:128',
        ]);
        $v->validate();
        if (! is_int($graph['schema_version'])) {
            throw ValidationException::withMessages(['graph.schema_version' => 'Schema version must be an integer.']);
        }
        foreach ($graph['nodes'] as $i => $node) {
            if (! is_int($node['type_version']) || ! is_int($node['position']['x']) && ! is_float($node['position']['x']) || ! is_int($node['position']['y']) && ! is_float($node['position']['y'])) {
                throw ValidationException::withMessages(["graph.nodes.$i" => 'Version and position must use JSON numeric types.']);
            }
            foreach ($node['schema_hints'] ?? [] as $field) {
                if (! is_bool($field['nullable'])) {
                    throw ValidationException::withMessages(["graph.nodes.$i.schema_hints" => 'Nullable must be a boolean.']);
                }
            }
            if (isset($node['config']['header']) && ! is_bool($node['config']['header'])) {
                throw ValidationException::withMessages(["graph.nodes.$i.config.header" => 'Header must be a boolean.']);
            }
            $prefix = "graph.nodes.$i.config";
            $required = $complete ? 'required' : 'sometimes';
            $rules = match ($node['type']) {
                'csv.source' => [
                    $prefix => 'array:asset_id,delimiter,header',
                    "$prefix.asset_id" => "$required|string|max:128",
                    "$prefix.delimiter" => ['sometimes', 'string', Rule::in([',', ';', ':', '|'])],
                    "$prefix.header" => 'sometimes|boolean',
                ],
                'transform.filter' => [
                    $prefix => 'array:field,operator,value',
                    "$prefix.field" => "$required|string|max:128",
                    "$prefix.operator" => [$required, Rule::in(['equals', 'not_equals', 'is_null', 'is_not_null', 'greater_than', 'less_than'])],
                    "$prefix.value" => ['sometimes', 'nullable', function ($attribute, $value, $fail) {
                        if (! is_scalar($value) || (is_string($value) && mb_strlen($value) > 4096)) {
                            $fail('Value must be a scalar of at most 4096 characters or null.');
                        }
                    }],
                ],
                'transform.map' => [
                    $prefix => 'array:mappings',
                    "$prefix.mappings" => "$required|array|list|min:1|max:256",
                    "$prefix.mappings.*" => 'required|array:source,target',
                    "$prefix.mappings.*.source" => 'required|string|max:128',
                    "$prefix.mappings.*.target" => 'required|string|max:128',
                ],
                'csv.destination' => [
                    $prefix => 'array:filename',
                    "$prefix.filename" => "$required|string|max:128|regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/",
                ],
                default => [],
            };
            Validator::make(['graph' => $graph], $rules)->validate();
            if ($node['type'] !== 'csv.source' && array_key_exists('schema_hints', $node)) {
                throw ValidationException::withMessages(["graph.nodes.$i.schema_hints" => 'Schema hints are supported only on CSV sources.']);
            }
            $names = array_column($node['schema_hints'] ?? [], 'name');
            if (count($names) !== count(array_unique($names))) {
                throw ValidationException::withMessages(["graph.nodes.$i.schema_hints" => 'Schema field names must be unique.']);
            }
            if (isset($node['config']['asset_id']) && $node['type'] === 'csv.source' && ! $this->assets->isReadyForOrganization($node['config']['asset_id'], $organizationId)) {
                throw ValidationException::withMessages(["$prefix.asset_id" => 'Asset is unavailable or does not belong to this organization.']);
            }
            if ($complete && $node['type'] === 'transform.filter' && isset($node['config']['operator']) && ! in_array($node['config']['operator'], ['is_null', 'is_not_null'], true) && ! array_key_exists('value', $node['config'])) {
                throw ValidationException::withMessages(["$prefix.value" => 'A comparison value is required.']);
            }
        }
        $result = $this->graphValidator->validate($graph, $complete);
        if ($result['errors']) {
            throw ValidationException::withMessages(['graph' => $result['errors']]);
        }

        return $result;
    }
}
