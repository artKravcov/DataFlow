$ErrorActionPreference = 'Stop'
$project = 'dataflow-runtime-test-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$composeFile = Join-Path $PSScriptRoot 'runtime-integration.compose.yaml'
if ($project -notmatch '^dataflow-runtime-test-[0-9a-f]{12}$') { throw 'Invalid isolated project name.' }

function Invoke-Compose {
    & docker compose --project-name $project --file $composeFile @args
    if ($LASTEXITCODE -ne 0) { throw "Isolated Compose command failed: $($args[0])" }
}

function New-Fixture {
    $result = (Invoke-Compose exec -T api php /tmp/worker-integration.php prepare-runtime | Out-String).Trim() | ConvertFrom-Json
    if ($result.execution_id -notmatch '^[1-9][0-9]{0,17}$') { throw 'Invalid fixture identifier.' }
    return [string]$result.execution_id
}

function Wait-Execution([string]$id, [string]$condition) {
    for ($i = 0; $i -lt 40; $i++) {
        $state = (Invoke-Compose exec -T api php /tmp/worker-integration.php status $id | Out-String).Trim() | ConvertFrom-Json
        if ($state.status -eq 'failed') { throw "Test execution $id failed." }
        if (($condition -eq 'published' -and $state.published) -or ($condition -eq 'succeeded' -and $state.status -eq 'succeeded')) { return }
        Start-Sleep -Seconds 1
    }
    throw "Timed out waiting for $condition on test execution $id."
}

try {
    Invoke-Compose up --build -d api | Out-Null
    $first = New-Fixture
    Invoke-Compose up --build -d queue-manager worker | Out-Null
    Wait-Execution $first 'succeeded'
    Invoke-Compose exec -T api php /tmp/worker-integration.php verify $first

    Invoke-Compose stop worker | Out-Null
    $second = New-Fixture
    Wait-Execution $second 'published'
    Invoke-Compose up -d worker | Out-Null
    Wait-Execution $second 'succeeded'
    Invoke-Compose exec -T api php /tmp/worker-integration.php verify $second

    Invoke-Compose restart redis | Out-Null
    $third = New-Fixture
    Wait-Execution $third 'succeeded'
    Invoke-Compose exec -T api php /tmp/worker-integration.php verify $third
    Write-Output 'Verified continuous queue manager, worker restart and Redis reconnection in isolated containers.'
} catch {
    & docker compose --project-name $project --file $composeFile logs --tail 30 api queue-manager worker
    throw
} finally {
    if ($project -match '^dataflow-runtime-test-[0-9a-f]{12}$') {
        & docker compose --project-name $project --file $composeFile down --volumes --timeout 5
    }
}
