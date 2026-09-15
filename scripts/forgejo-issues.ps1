param(
    [ValidateSet('list', 'comment', 'close')]
    [string]$Action = 'list',
    [int]$Issue = 0,
    [string]$Body = ''
)

$ErrorActionPreference = 'Stop'
$remote = git remote get-url origin
if ($LASTEXITCODE -ne 0 -or $remote -notmatch '^https://([^/]+)/([^/]+)/([^/.]+)(?:\.git)?$') {
    throw 'The origin remote must be an HTTPS Forgejo repository URL.'
}

$hostName = $Matches[1]
$owner = $Matches[2]
$repo = $Matches[3]
$credentialInput = "protocol=https`nhost=$hostName`n`n"
$credentialOutput = $credentialInput | git credential fill
if ($LASTEXITCODE -ne 0) { throw 'Git Credential Manager did not return Forgejo credentials.' }
$credential = @{}
$credentialOutput | ForEach-Object {
    if ($_ -match '^([^=]+)=(.*)$') { $credential[$Matches[1]] = $Matches[2] }
}
if (-not $credential.password) { throw 'No Forgejo token/password is stored in Git Credential Manager.' }

$basicBytes = [Text.Encoding]::UTF8.GetBytes("$($credential.username):$($credential.password)")
$headers = @{ Authorization = "Basic $([Convert]::ToBase64String($basicBytes))" }
$base = "https://$hostName/api/v1/repos/$owner/$repo"

switch ($Action) {
    'list' {
        $response = Invoke-RestMethod -Headers $headers -Uri "$base/issues?state=open&limit=100"
        @($response) | Select-Object number, title, state, html_url | ConvertTo-Json -Depth 4
    }
    'comment' {
        if ($Issue -le 0 -or [string]::IsNullOrWhiteSpace($Body)) { throw 'comment requires -Issue and -Body.' }
        Invoke-RestMethod -Method Post -Headers $headers -ContentType 'application/json' `
            -Uri "$base/issues/$Issue/comments" -Body (@{ body = $Body } | ConvertTo-Json) | Out-Null
        Write-Output "Commented on issue #$Issue"
    }
    'close' {
        if ($Issue -le 0) { throw 'close requires -Issue.' }
        if (-not [string]::IsNullOrWhiteSpace($Body)) {
            Invoke-RestMethod -Method Post -Headers $headers -ContentType 'application/json' `
                -Uri "$base/issues/$Issue/comments" -Body (@{ body = $Body } | ConvertTo-Json) | Out-Null
        }
        Invoke-RestMethod -Method Patch -Headers $headers -ContentType 'application/json' `
            -Uri "$base/issues/$Issue" -Body (@{ state = 'closed' } | ConvertTo-Json) | Out-Null
        Write-Output "Closed issue #$Issue"
    }
}
