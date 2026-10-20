<?php

namespace App\Graph;

/** Checks graph topology and known schema fields. Structural input validation is required before calling. */
final class GraphValidator
{
    public function __construct(private NodeRegistry $registry) {}

    /** @return array{errors: list<string>, warnings: list<string>} */
    public function validate(array $graph, bool $complete = false): array
    {
        $errors = [];
        $warnings = [];
        $definitions = $this->registry->all();
        $nodes = [];
        $incoming = [];
        $outgoing = [];
        $edgeIds = [];
        $edgeKeys = [];
        foreach ($graph['nodes'] as $node) {
            $id = $node['id'];
            if (isset($nodes[$id])) {
                $errors[] = "Duplicate node: $id";

                continue;
            }
            $nodes[$id] = $node;
            $definition = $definitions[$node['type']] ?? null;
            if (! $definition || $definition['type_version'] !== $node['type_version']) {
                $errors[] = "Unknown node type or version: $id";
            }
        }
        foreach ($graph['edges'] as $edge) {
            $id = $edge['id'];
            $key = json_encode([$edge['source'], $edge['source_port'], $edge['target'], $edge['target_port']]);
            if (isset($edgeIds[$id]) || isset($edgeKeys[$key])) {
                $errors[] = "Duplicate edge: $id";
            }
            $edgeIds[$id] = $edgeKeys[$key] = true;
            $source = $nodes[$edge['source']] ?? null;
            $target = $nodes[$edge['target']] ?? null;
            if (! $source || ! $target) {
                $errors[] = "Edge has missing endpoint: $id";

                continue;
            }
            $srcDef = $definitions[$source['type']] ?? null;
            $dstDef = $definitions[$target['type']] ?? null;
            $out = $srcDef['outputs'][$edge['source_port']] ?? null;
            $in = $dstDef['inputs'][$edge['target_port']] ?? null;
            if ($out === null || $in === null || $out !== $in) {
                $errors[] = "Incompatible ports: $id";
            }
            $incoming[$edge['target']][] = $edge['source'];
            $outgoing[$edge['source']][] = $edge['target'];
            if (count($incoming[$edge['target']]) > 1) {
                $errors[] = "Multiple inputs: {$edge['target']}";
            }
        }
        $indegree = [];
        foreach ($nodes as $id => $node) {
            $indegree[$id] = count($incoming[$id] ?? []);
        }
        $queue = array_keys(array_filter($indegree, fn ($degree) => $degree === 0));
        $order = [];
        while ($queue) {
            $id = array_shift($queue);
            $order[] = $id;
            foreach ($outgoing[$id] ?? [] as $target) {
                if (--$indegree[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }
        if (count($order) !== count($nodes)) {
            $errors[] = 'Graph contains a cycle';
        }
        if ($complete) {
            $sources = array_filter($nodes, fn ($node) => ($definitions[$node['type']]['category'] ?? null) === 'source');
            $destinations = array_filter($nodes, fn ($node) => ($definitions[$node['type']]['category'] ?? null) === 'destination');
            if (count($sources) !== 1 || ! $destinations) {
                $errors[] = 'A complete graph requires exactly one source and at least one destination';
            }
            foreach ($nodes as $id => $node) {
                $category = $definitions[$node['type']]['category'] ?? null;
                if ($category !== 'source' && empty($incoming[$id])) {
                    $errors[] = "Missing input: $id";
                }
                if ($category === 'transform' && empty($outgoing[$id])) {
                    $errors[] = "Transform has no output: $id";
                }
            }
        }
        $schemas = [];
        foreach ($order as $id) {
            $node = $nodes[$id];
            $type = $node['type'];
            $config = $node['config'];
            $schema = $type === 'csv.source' ? ($node['schema_hints'] ?? null) : ($schemas[$incoming[$id][0] ?? ''] ?? null);
            if ($type === 'csv.destination' && $schema === null) {
                $warnings[] = "Schema unknown: $id";
            }
            if ($type === 'transform.filter' && isset($config['field'])) {
                if ($schema === null) {
                    $warnings[] = "Schema unknown: $id";
                } elseif (! in_array($config['field'], array_column($schema, 'name'), true)) {
                    $errors[] = "Filter field missing: $id";
                }
            }
            if ($type === 'transform.map' && isset($config['mappings']) && is_array($config['mappings'])) {
                $targets = [];
                $mapped = [];
                foreach ($config['mappings'] as $mapping) {
                    if (! isset($mapping['source'], $mapping['target'])) {
                        continue;
                    }
                    if (in_array($mapping['target'], $targets, true)) {
                        $errors[] = "Duplicate map target: $id";
                    }
                    $targets[] = $mapping['target'];
                    if ($schema !== null) {
                        $fields = array_column($schema, null, 'name');
                        if (! isset($fields[$mapping['source']])) {
                            $errors[] = "Map source missing: $id";
                        } else {
                            $field = $fields[$mapping['source']];
                            $field['name'] = $mapping['target'];
                            $mapped[] = $field;
                        }
                    }
                }
                if ($schema === null) {
                    $warnings[] = "Schema unknown: $id";
                } else {
                    $schema = $mapped;
                }
            }
            $schemas[$id] = $schema;
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }
}
