param(
	[string]$Version = '1.2.1'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent $PSScriptRoot
$buildRoot = Join-Path $root 'build'
$stagingRoot = Join-Path $buildRoot '_package-staging'
$wpPackageRoot = Join-Path $stagingRoot 'daiosity-conflict-debugger'
$wpZipPath = Join-Path $buildRoot 'daiosity-conflict-debugger-wp-admin.zip'
$hostZipPath = Join-Path $buildRoot 'daiosity-conflict-debugger.zip'
$legacyHostZipPath = Join-Path $buildRoot 'daiosity-conflict-debugger-host-extract.zip'
$staleZipPaths = @(
	$legacyHostZipPath,
	(Join-Path $buildRoot 'conflict-debugger-wp-admin.zip'),
	(Join-Path $buildRoot 'conflict-debugger.zip'),
	(Join-Path $buildRoot '_debug.zip')
)

$requiredItems = @(
	'daiosity-conflict-debugger.php',
	'readme.txt',
	'uninstall.php',
	'LICENSE',
	'assets',
	'includes',
	'languages'
)

function New-NormalizedZip {
	param(
		[Parameter(Mandatory = $true)]
		[string]$SourceRoot,
		[Parameter(Mandatory = $true)]
		[string]$ZipPath,
		[string]$RootPrefix = ''
	)

	$zipArchive = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)

	try {
		$normalizedSourceRoot = [System.IO.Path]::GetFullPath($SourceRoot)

		if ($RootPrefix -ne '') {
			$rootEntryName = ($RootPrefix.TrimEnd('/')) + '/'
			$rootEntry = $zipArchive.CreateEntry($rootEntryName)
			$rootEntry.LastWriteTime = [DateTimeOffset]::Parse('2020-01-01T00:00:00Z')
		}

		Get-ChildItem -LiteralPath $normalizedSourceRoot -Recurse -Force | Sort-Object FullName | ForEach-Object {
			$fullName = [System.IO.Path]::GetFullPath($_.FullName)
			$relativePath = $fullName.Substring($normalizedSourceRoot.Length).TrimStart('\', '/')
			$entryName = if ($RootPrefix -ne '') {
				($RootPrefix.TrimEnd('/') + '/' + ($relativePath -replace '\\', '/')).TrimStart('/')
			}
			else {
				($relativePath -replace '\\', '/')
			}

			if ($_.PSIsContainer) {
				if ($entryName -ne '') {
					$entry = $zipArchive.CreateEntry($entryName.TrimEnd('/') + '/')
					$entry.LastWriteTime = [DateTimeOffset]::Parse('2020-01-01T00:00:00Z')
				}
			}
			else {
				$entry = $zipArchive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
				$entry.LastWriteTime = [DateTimeOffset]::Parse('2020-01-01T00:00:00Z')
				$inputStream = [System.IO.File]::OpenRead($fullName)
				try {
					$outputStream = $entry.Open()
					try { $inputStream.CopyTo($outputStream) }
					finally { $outputStream.Dispose() }
				}
				finally { $inputStream.Dispose() }
			}
		}
	}
	finally {
		$zipArchive.Dispose()
	}
}

function Assert-ZipLayout {
	param(
		[Parameter(Mandatory = $true)]
		[string]$ZipPath,
		[Parameter(Mandatory = $true)]
		[string]$ExpectedMainFile,
		[string]$ExpectedRoot = ''
	)

	$zipArchive = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)

	try {
		$entries = @($zipArchive.Entries | Where-Object { $_.FullName -ne '' })
		if (@($entries | Where-Object { $_.FullName -match '\\' }).Count -gt 0) {
			throw "Archive contains Windows-style path separators: $ZipPath"
		}

		if ($null -eq ($entries | Where-Object { $_.FullName -eq $ExpectedMainFile } | Select-Object -First 1)) {
			throw "Archive is missing its expected main plugin file: $ExpectedMainFile"
		}

		if ($ExpectedRoot -ne '') {
			$prefix = $ExpectedRoot.TrimEnd('/') + '/'
			$outsideRoot = @($entries | Where-Object { !$_.FullName.StartsWith($prefix, [System.StringComparison]::Ordinal) })
			if ($outsideRoot.Count -gt 0) {
				throw "Archive contains entries outside the required root folder '$ExpectedRoot'."
			}
		}
	}
	finally {
		$zipArchive.Dispose()
	}
}

if (!(Test-Path $buildRoot)) {
	New-Item -ItemType Directory -Path $buildRoot | Out-Null
}

if (Test-Path $stagingRoot) {
	if ([System.IO.Path]::GetFullPath($stagingRoot) -ne [System.IO.Path]::GetFullPath((Join-Path $root 'build/_package-staging'))) { throw 'Invalid staging directory.' }
	Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

foreach ($item in $requiredItems) {
	$sourcePath = Join-Path $root $item
	if (!(Test-Path $sourcePath)) {
		throw "Missing required package item: $item"
	}
}

$mainFileContent = Get-Content -LiteralPath (Join-Path $root 'daiosity-conflict-debugger.php') -Raw
$readmeContent = Get-Content -LiteralPath (Join-Path $root 'readme.txt') -Raw
if ($mainFileContent -notmatch "(?m)^\s*\* Version:\s*$([regex]::Escape($Version))\s*$" -or $mainFileContent -notmatch "define\(\s*'PCD_VERSION',\s*'$([regex]::Escape($Version))'\s*\)") {
	throw "Plugin header or PCD_VERSION does not match requested build version $Version."
}
if ($readmeContent -notmatch "(?m)^Stable tag:\s*$([regex]::Escape($Version))\s*$") {
	throw "readme.txt Stable tag does not match requested build version $Version."
}

foreach ($zipPath in @($wpZipPath, $hostZipPath) + $staleZipPaths) {
	if (Test-Path $zipPath) {
		Remove-Item -LiteralPath $zipPath -Force
	}
}

New-Item -ItemType Directory -Path $stagingRoot | Out-Null
New-Item -ItemType Directory -Path $wpPackageRoot | Out-Null

foreach ($item in $requiredItems) {
	$sourcePath = Join-Path $root $item
	Copy-Item -LiteralPath $sourcePath -Destination $wpPackageRoot -Recurse
}

New-NormalizedZip -SourceRoot $wpPackageRoot -ZipPath $wpZipPath -RootPrefix 'daiosity-conflict-debugger'
# Both public filenames are WordPress installers; no ambiguous flat archive.
Copy-Item -LiteralPath $wpZipPath -Destination $hostZipPath

Assert-ZipLayout -ZipPath $wpZipPath -ExpectedRoot 'daiosity-conflict-debugger' -ExpectedMainFile 'daiosity-conflict-debugger/daiosity-conflict-debugger.php'
Assert-ZipLayout -ZipPath $hostZipPath -ExpectedRoot 'daiosity-conflict-debugger' -ExpectedMainFile 'daiosity-conflict-debugger/daiosity-conflict-debugger.php'

if ([System.IO.Path]::GetFullPath($stagingRoot) -ne [System.IO.Path]::GetFullPath((Join-Path $root 'build/_package-staging'))) { throw 'Invalid staging directory.' }
Remove-Item -LiteralPath $stagingRoot -Recurse -Force

Get-ChildItem $wpZipPath, $hostZipPath | Select-Object Name, FullName, Length, LastWriteTime
