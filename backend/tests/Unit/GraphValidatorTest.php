<?php

namespace Tests\Unit;

use App\Graph\GraphValidator;
use App\Graph\NodeRegistry;
use PHPUnit\Framework\TestCase;

class GraphValidatorTest extends TestCase
{
    private function graph(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 3).'/contracts/fixtures/csv-filter-map-v1.json'), true, flags: JSON_THROW_ON_ERROR)['graph'];
    }

    private function check(array $graph, bool $complete = true): array
    {
        return (new GraphValidator(new NodeRegistry))->validate($graph, $complete);
    }

    public function test_fixture_propagates_schema(): void
    {
        self::assertSame(['errors' => [], 'warnings' => []], $this->check($this->graph()));
    }

    public function test_rejects_cycle_ports_and_duplicate_input(): void
    {
        $graph = $this->graph();
        $graph['edges'][] = ['id' => 'cycle', 'source' => 'map', 'source_port' => 'records', 'target' => 'filter', 'target_port' => 'records'];
        $graph['edges'][0]['source_port'] = 'invalid';
        $errors = $this->check($graph)['errors'];
        self::assertContains('Graph contains a cycle', $errors);
        self::assertContains('Incompatible ports: e1', $errors);
        self::assertContains('Multiple inputs: filter', $errors);
    }

    public function test_rejects_unknown_version_and_missing_or_duplicate_fields(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config']['field'] = 'absent';
        $graph['nodes'][2]['config']['mappings'][0]['source'] = 'absent';
        $graph['nodes'][2]['config']['mappings'][1]['target'] = 'id';
        $graph['nodes'][3]['type_version'] = 2;
        $errors = $this->check($graph)['errors'];
        self::assertContains('Unknown node type or version: output', $errors);
        self::assertContains('Filter field missing: filter', $errors);
        self::assertContains('Map source missing: map', $errors);
        self::assertContains('Duplicate map target: map', $errors);
    }

    public function test_unknown_schema_warns_and_empty_draft_is_not_complete(): void
    {
        $graph = $this->graph();
        unset($graph['nodes'][0]['schema_hints']);
        self::assertContains('Schema unknown: filter', $this->check($graph)['warnings']);
        $empty = ['schema_version' => 1, 'nodes' => [], 'edges' => []];
        self::assertSame([], $this->check($empty, false)['errors']);
        self::assertNotEmpty($this->check($empty)['errors']);
    }
}
