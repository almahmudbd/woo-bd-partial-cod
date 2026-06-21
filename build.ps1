# Build a clean, distributable ZIP of the plugin (Windows-native, no bash/zip needed).
#
# Includes only the files WordPress needs (main file, includes/, assets/,
# templates/, README, languages/) and excludes all dev/IDE/agent tooling.
# The version number is read from the plugin header automatically.
#
# IMPORTANT: this builds the archive with forward-slash paths via .NET's
# ZipArchive. Windows PowerShell's built-in Compress-Archive writes BACK-slashes
# inside the ZIP, which breaks extraction in WordPress ("Plugin file does not
# exist"). Do not switch this back to Compress-Archive.
#
# Usage:  right-click > "Run with PowerShell", or:
#           powershell -ExecutionPolicy Bypass -File build.ps1
#         (or simply double-click build.cmd)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$slug = 'aam-bd-partial-cod-for-wc'
$main = "$slug.php"

if (-not (Test-Path $main)) {
	Write-Error "$main not found - run this from the plugin folder."
	exit 1
}

# Read "Version: X.Y.Z" from the plugin header.
$match   = Select-String -Path $main -Pattern 'Version:\s*([0-9][0-9.]*)' | Select-Object -First 1
$version = if ($match) { $match.Matches[0].Groups[1].Value } else { 'dev' }

$zip = Join-Path $PSScriptRoot "$slug-$version.zip"

# The actual plugin payload - anything not listed here is left out of the ZIP.
$include = @($main, 'readme.txt', 'README.md', 'license.txt', 'includes', 'assets', 'templates')
if (Test-Path 'languages') { $include += 'languages' }

# Gather every file to archive, with its ZIP entry name (slug/forward/slash/path).
$entries = @()
foreach ($item in $include) {
	if (-not (Test-Path $item)) { continue }

	if (Test-Path $item -PathType Container) {
		Get-ChildItem -Path $item -Recurse -File | Where-Object { $_.Name -notmatch '^(screenshot-\d+\.|icon-\d+x\d+\.)' } | ForEach-Object {
			$rel = $_.FullName.Substring($PSScriptRoot.Length + 1) -replace '\\', '/'
			$entries += [PSCustomObject]@{ Full = $_.FullName; Name = "$slug/$rel" }
		}
	} else {
		$leaf = Split-Path $item -Leaf
		$entries += [PSCustomObject]@{ Full = (Resolve-Path $item).Path; Name = "$slug/$leaf" }
	}
}

Write-Host "Building $slug-$version.zip (version $version) - $($entries.Count) files..."

if (Test-Path $zip) { Remove-Item -Force $zip }

Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

$stream  = [System.IO.File]::Open($zip, [System.IO.FileMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
	foreach ($e in $entries) {
		$entry      = $archive.CreateEntry($e.Name, [System.IO.Compression.CompressionLevel]::Optimal)
		$entryOut   = $entry.Open()
		$fileBytes  = [System.IO.File]::ReadAllBytes($e.Full)
		$entryOut.Write($fileBytes, 0, $fileBytes.Length)
		$entryOut.Dispose()
	}
} finally {
	$archive.Dispose()
	$stream.Dispose()
}

Write-Host "Created $zip"
