$ErrorActionPreference = 'Stop'
$testContainer = 'dataflow-integration-api'
$testToken = 'integration-test-service-token-0123456789'
$created = $false
$fixture = $null
try {
    $existing = docker ps -a --filter "name=^/$testContainer`$" --format '{{.Names}}'
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect Docker containers.' }
    if ($existing -contains $testContainer) { throw 'Test container name is already in use; stop and inspect it before retrying.' }
    docker compose build --quiet backend worker
    if ($LASTEXITCODE -ne 0) { throw 'Build failed.' }
    docker compose run -d --no-deps --name $testContainer -e APP_ENV=testing -e DB_DATABASE=dataflow_test -e "INTERNAL_SERVICE_TOKEN=$testToken" backend php -S 0.0.0.0:8000 -t public public/index.php | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Cannot start isolated API.' }
    $created = $true
    docker cp worker/scripts/worker-integration.php "${testContainer}:/tmp/worker-integration.php"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot copy fixture script.' }
    $raw = (docker exec $testContainer php /tmp/worker-integration.php prepare | Out-String).Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Fixture preparation failed.' }
    $fixture = $raw | ConvertFrom-Json
    if ($fixture.execution_id -notmatch '^[1-9][0-9]{0,17}$' -or $fixture.stream -notmatch '^dataflow:contract-test:[0-9a-f-]{36}$') { throw 'Invalid isolated fixture.' }
    docker compose run --rm --no-deps -e DATAFLOW_INTEGRATION=1 -e DATAFLOW_TEST_API_URL=http://dataflow-integration-api:8000 -e "INTERNAL_SERVICE_TOKEN=$testToken" worker node dist/integration-redis-check.js $fixture.stream
    if ($LASTEXITCODE -ne 0) { throw 'Worker integration failed.' }
    docker exec $testContainer php /tmp/worker-integration.php verify $fixture.execution_id
    if ($LASTEXITCODE -ne 0) { throw 'Integration result verification failed.' }
} finally {
    if ($created) {
        if ($null -ne $fixture -and $fixture.execution_id -match '^[1-9][0-9]{0,17}$') {
            docker exec $testContainer php /tmp/worker-integration.php cleanup $fixture.execution_id
        }
        docker rm -f $testContainer | Out-Null
    }
}
