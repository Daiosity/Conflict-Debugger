param(
    [string]$SourceRoot = (Join-Path $PSScriptRoot '..\tests\fixtures\wp-plugins'),
    [string]$DestinationRoot = (Join-Path $PSScriptRoot '..\WordPress Site\app\public\wp-content\plugins')
)

$ErrorActionPreference = 'Stop'

$resolvedSource = (Resolve-Path $SourceRoot).Path

if (-not (Test-Path $DestinationRoot)) {
    throw "Destination plugin directory was not found: $DestinationRoot"
}

$fixtureDirectories = Get-ChildItem -LiteralPath $resolvedSource -Directory | Sort-Object Name

if ($fixtureDirectories.Count -eq 0) {
    throw "No fixture plugin directories were found in $resolvedSource"
}

foreach ($fixtureDirectory in $fixtureDirectories) {
    $targetPath = Join-Path $DestinationRoot $fixtureDirectory.Name

    if (Test-Path $targetPath) {
        Remove-Item -LiteralPath $targetPath -Recurse -Force
    }

    Copy-Item -LiteralPath $fixtureDirectory.FullName -Destination $targetPath -Recurse -Force
    Write-Host ("Synced fixture plugin: {0}" -f $fixtureDirectory.Name)
}

Write-Host ("Fixture sync complete. Installed {0} fixture plugins into {1}" -f $fixtureDirectories.Count, $DestinationRoot)
