# Build a clean, distributable ZIP of the plugin (Windows-native, no bash/zip needed).
#
# Includes only the files WordPress needs (main file, includes/, assets/,
# templates/, README, languages/) and excludes all dev/IDE/agent tooling.
# The version number is read from the plugin header automatically.
#
# Usage:  right-click > "Run with PowerShell", or:
#           powershell -ExecutionPolicy Bypass -File build.ps1
#         (or simply double-click build.cmd)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$slug = 'woo-bd-partial-cod'
$main = "$slug.php"

if (-not (Test-Path $main)) {
	Write-Error "$main not found - run this from the plugin folder."
	exit 1
}

# Read "Version: X.Y.Z" from the plugin header.
$match   = Select-String -Path $main -Pattern 'Version:\s*([0-9][0-9.]*)' | Select-Object -First 1
$version = if ($match) { $match.Matches[0].Groups[1].Value } else { 'dev' }

$buildDir = Join-Path $PSScriptRoot 'build'
$stage    = Join-Path $buildDir $slug
$zip      = Join-Path $PSScriptRoot "$slug-$version.zip"

# The actual plugin payload - anything not listed here is left out of the ZIP.
$include = @($main, 'README.md', 'includes', 'assets', 'templates')
if (Test-Path 'languages') { $include += 'languages' }

Write-Host "Building $slug-$version.zip (version $version)..."

if (Test-Path $buildDir) { Remove-Item -Recurse -Force $buildDir }
if (Test-Path $zip)      { Remove-Item -Force $zip }
New-Item -ItemType Directory -Path $stage | Out-Null

foreach ($item in $include) {
	if (Test-Path $item) {
		Copy-Item -Recurse -Force -Path $item -Destination $stage
	}
}

Compress-Archive -Path $stage -DestinationPath $zip -Force
Remove-Item -Recurse -Force $buildDir

Write-Host "Created $zip"
