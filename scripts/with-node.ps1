param(
    [Parameter(Mandatory = $true, Position = 0)][string]$Command,
    [Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments
)
$ErrorActionPreference = 'Stop'
$taskProject = Split-Path -Parent $PSScriptRoot
$taskVersion = (Get-Content -LiteralPath (Join-Path $taskProject '.nvmrc') -Raw).Trim()
if ($taskVersion -notmatch '^24\.[0-9]+\.[0-9]+$') { throw 'The project must pin a Node 24 release.' }
$taskTools = Join-Path $env:LOCALAPPDATA 'GameXSports\tools'
$taskNodeDirectory = Join-Path $taskTools "node-v$taskVersion-win-x64"
if (-not (Test-Path -LiteralPath (Join-Path $taskNodeDirectory 'node.exe'))) {
    New-Item -ItemType Directory -Path $taskTools -Force | Out-Null
    $taskName = "node-v$taskVersion-win-x64.zip"
    $taskArchive = Join-Path $taskTools $taskName
    $taskUrl = "https://nodejs.org/dist/v$taskVersion"
    Invoke-WebRequest -Uri "$taskUrl/$taskName" -OutFile $taskArchive -UseBasicParsing
    $taskChecksums = (Invoke-WebRequest -Uri "$taskUrl/SHASUMS256.txt" -UseBasicParsing).Content
    $taskChecksum = ($taskChecksums -split "`n" | Where-Object { $_.Trim().EndsWith("  $taskName") } | Select-Object -First 1).Split(' ')[0]
    if (-not $taskChecksum -or (Get-FileHash -LiteralPath $taskArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskChecksum) { throw 'Node archive checksum verification failed.' }
    Expand-Archive -LiteralPath $taskArchive -DestinationPath $taskTools -Force
}
$taskPreviousPath = $env:Path
try {
    $env:Path = $taskNodeDirectory + ';' + $env:Path
    & $Command @Arguments
    $taskExitCode = $LASTEXITCODE
} finally {
    $env:Path = $taskPreviousPath
}
exit $taskExitCode
