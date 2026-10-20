<?php

namespace App\Graph;

/** Catalog describes contracts only; runtime handlers do not yet exist. */
final class NodeRegistry
{
    public function all(): array
    {
        return [
            'csv.source' => ['type_version' => 1, 'category' => 'source', 'inputs' => [], 'outputs' => ['records' => 'dataset'], 'schema_rule' => 'manual'],
            'transform.filter' => ['type_version' => 1, 'category' => 'transform', 'inputs' => ['records' => 'dataset'], 'outputs' => ['records' => 'dataset'], 'schema_rule' => 'preserve'],
            'transform.map' => ['type_version' => 1, 'category' => 'transform', 'inputs' => ['records' => 'dataset'], 'outputs' => ['records' => 'dataset'], 'schema_rule' => 'map'],
            'csv.destination' => ['type_version' => 1, 'category' => 'destination', 'inputs' => ['records' => 'dataset'], 'outputs' => [], 'schema_rule' => 'consume'],
        ];
    }

    public function catalog(): array
    {
        $field = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
        $schemas = [
            'csv.source' => ['properties' => ['asset_id' => $field, 'delimiter' => ['type' => 'string', 'enum' => [',', ';', ':', '|']], 'header' => ['type' => 'boolean']], 'required' => ['asset_id']],
            'transform.filter' => ['properties' => ['field' => $field, 'operator' => ['enum' => ['equals', 'not_equals', 'is_null', 'is_not_null', 'greater_than', 'less_than']], 'value' => ['type' => ['string', 'number', 'boolean', 'null']]], 'required' => ['field', 'operator']],
            'transform.map' => ['properties' => ['mappings' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 256, 'items' => ['type' => 'object', 'properties' => ['source' => $field, 'target' => $field], 'required' => ['source', 'target'], 'additionalProperties' => false]]], 'required' => ['mappings']],
            'csv.destination' => ['properties' => ['filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*$']], 'required' => ['filename']],
        ];
        $labels = ['csv.source' => 'CSV source', 'transform.filter' => 'Filter', 'transform.map' => 'Map', 'csv.destination' => 'CSV destination'];
        $result = [];
        foreach ($this->all() as $type => $definition) {
            $definition['inputs'] = (object) $definition['inputs'];
            $definition['outputs'] = (object) $definition['outputs'];
            $result[] = ['type' => $type, 'label' => $labels[$type], ...$definition, 'config_schema' => ['type' => 'object', ...$schemas[$type], 'additionalProperties' => false], 'input_contract' => (object) $definition['inputs'], 'output_contract' => (object) $definition['outputs'], 'runtime_available' => false];
        }

        return $result;
    }
}
