[CmdletBinding()]
param(
    [ValidateSet('Run', 'Preflight', 'Monitor')]
    [string]$Mode = 'Run',
    [string]$RunId,
    [switch]$Yes,
    [string]$Server = '192.168.1.4',
    [string]$User = 'root',
    [string]$RemoteScript = '/mnt/cache/DevelopmentProjects/unraid-extensions/unraid-plg-aicliagents/bin/publish-to-factory.sh'
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) {
    throw 'OpenSSH client (ssh.exe) is required.'
}

if ($RunId -and $Mode -ne 'Monitor') {
    throw '-RunId is valid only with -Mode Monitor.'
}
if ($RunId -and $RunId -notmatch '^[A-Za-z0-9._-]+$') {
    throw 'RunId contains unsupported characters.'
}

$remoteArgs = [System.Collections.Generic.List[string]]::new()
switch ($Mode) {
    'Preflight' { $remoteArgs.Add('--preflight') }
    'Monitor' {
        $remoteArgs.Add('--monitor')
        if ($RunId) { $remoteArgs.Add($RunId) }
    }
    'Run' {
        if ($Yes) { $remoteArgs.Add('--yes') }
    }
}

$quotedArgs = $remoteArgs | ForEach-Object { "'$_'" }
$remoteCommand = "bash '$RemoteScript'"
if ($quotedArgs.Count -gt 0) {
    $remoteCommand += ' ' + ($quotedArgs -join ' ')
}

Write-Host "[factory-publish] $Mode on $User@$Server"
& ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=4 "$User@$Server" $remoteCommand
$exitCode = $LASTEXITCODE
if ($exitCode -ne 0) {
    Write-Error "Factory publisher failed with exit code $exitCode."
}
exit $exitCode
