param([string]$Version)

# Keep the old command available while using one validated packaging implementation.
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'build-standard-zip.ps1') @PSBoundParameters
