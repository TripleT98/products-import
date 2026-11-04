#Requires -Version 5.1
[CmdletBinding()]
param(
    [ValidateSet('Prompt', 'Load', 'Skip')]
    [string]$DemoData = 'Prompt',
    [string]$DockerPath,
    [switch]$SkipBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-Compose {
    param([string[]]$Arguments)
    & $script:Docker @script:ComposeArguments @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose failed ($LASTEXITCODE): $($Arguments[0]). See the output above."
    }
}

Push-Location $PSScriptRoot
try {
    if ($DockerPath) {
        $script:Docker = (Get-Command $DockerPath -ErrorAction Stop).Source
    } else {
        $command = Get-Command docker -ErrorAction SilentlyContinue
        if ($command) {
            $script:Docker = $command.Source
        } else {
            $desktopDocker = Join-Path $env:ProgramFiles 'Docker/Docker/resources/bin/docker.exe'
            if (!(Test-Path -LiteralPath $desktopDocker)) {
                throw 'Docker CLI not found. Install Docker Desktop, start it and enable Linux containers.'
            }
            $script:Docker = $desktopDocker
        }
    }
    $engine = & $script:Docker info --format '{{.OSType}}'
    if ($LASTEXITCODE -ne 0) { throw 'Docker Engine is unavailable. Start Docker Desktop and try again.' }
    if (($engine -join '').Trim() -ne 'linux') { throw 'Switch Docker Desktop to Linux containers.' }
    & $script:Docker compose version
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose v2 is required.' }

    if (!(Test-Path -LiteralPath '.env')) {
        $settings = Get-Content -LiteralPath '.env.example' -Raw
        foreach ($key in @('POSTGRES_PASSWORD', 'RABBITMQ_DEFAULT_PASS', 'JWT_SECRET')) {
            $bytes = New-Object byte[] 48
            $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
            try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
            $value = [Convert]::ToBase64String($bytes)
            $settings = [regex]::Replace($settings, '(?m)^' + $key + '=.*$', $key + '=' + $value)
        }
        # CreateNew never overwrites an existing configuration, including a concurrent launch.
        $stream = [IO.File]::Open((Join-Path $PSScriptRoot '.env'), [IO.FileMode]::CreateNew, [IO.FileAccess]::Write)
        try {
            $encoded = (New-Object Text.UTF8Encoding $false).GetBytes($settings)
            $stream.Write($encoded, 0, $encoded.Length)
        } finally { $stream.Dispose() }
        Write-Host 'Created .env with random database, broker and JWT secrets.'
    } else {
        Write-Host 'Using existing .env; settings and passwords are preserved.'
    }

    $script:ComposeArguments = @('compose', '--project-directory', $PSScriptRoot, '--env-file', (Join-Path $PSScriptRoot '.env'), '-f', (Join-Path $PSScriptRoot 'compose.yaml'))
    Invoke-Compose -Arguments @('config', '--quiet')
    if (!$SkipBuild) {
        Write-Host 'Building application images (the first run may take several minutes)...'
        Invoke-Compose -Arguments @('build', 'app', 'worker', 'nginx')
    }
    # No worker may process jobs while the database schema is being updated.
    Invoke-Compose -Arguments @('stop', 'worker')
    Invoke-Compose -Arguments @('up', '-d', '--wait', '--wait-timeout', '180', 'app', 'db', 'rabbitmq', 'nginx')
    Invoke-Compose -Arguments @('exec', '-T', 'app', 'php', 'bin/console', 'migrations:migrate', '--no-interaction')
    Invoke-Compose -Arguments @('exec', '-T', 'app', 'php', 'bin/console', 'orm:validate-schema')

    $databaseUser = ((Invoke-Compose -Arguments @('exec', '-T', 'db', 'printenv', 'POSTGRES_USER')) -join '').Trim()
    $databaseName = ((Invoke-Compose -Arguments @('exec', '-T', 'db', 'printenv', 'POSTGRES_DB')) -join '').Trim()
    $sql = 'SELECT (SELECT COUNT(*) FROM users), (SELECT COUNT(*) FROM products);'
    # Pass SQL through stdin: avoids native-command quoting differences between PowerShell 5.1 and 7.
    $counts = (($sql | & $script:Docker @script:ComposeArguments exec -T db psql -v ON_ERROR_STOP=1 -U $databaseUser -d $databaseName -At) -join '').Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Could not query database counts.' }
    if ($counts -notmatch '^(\d+)\|(\d+)$') { throw 'Could not determine whether the database is empty.' }
    $users = [long]$Matches[1]
    $products = [long]$Matches[2]
    $loaded = $false
    if ($users -eq 0 -and $products -eq 0) {
        $load = $DemoData -eq 'Load'
        if ($DemoData -eq 'Prompt') {
            Write-Host 'The database is empty. Demo data creates a login and 48 sample products with artificial images.'
            Write-Host 'There is currently no separate user-registration command.'
            $answer = Read-Host 'Load demo data? [y/N]'
            $load = $answer -match '^(?i:y|yes)$'
        }
        if ($load) {
            Invoke-Compose -Arguments @('exec', '-T', '--user', 'www-data', '-e', 'APP_ENV=development', 'app', 'php', 'bin/console', 'fixtures:load')
            $loaded = $true
        }
    } elseif ($DemoData -eq 'Load') {
        Write-Host 'Database is not empty; demo loading skipped to preserve existing records.'
    }
    if ($users -eq 0 -and !$loaded) {
        Write-Warning 'No login user exists. Demo data was not loaded; user provisioning is required before signing in.'
    }

    Invoke-Compose -Arguments @('up', '-d', '--wait', '--wait-timeout', '180', 'worker')
    $address = ((Invoke-Compose -Arguments @('port', 'nginx', '80')) -join '').Trim()
    if ($address -notmatch '^127\.0\.0\.1:\d+$') { throw 'Could not determine the application URL.' }
    $url = 'http://' + $address
    $health = Invoke-RestMethod -Uri ($url + '/api/health') -TimeoutSec 15
    if ($health.status -ne 'ok') { throw 'Application health check did not return ok.' }
    Invoke-Compose -Arguments @('ps')
    Write-Host "Application is ready: $url/login"
    Write-Host "API documentation: $url/docs/"
    if ($loaded) { Write-Host 'Local demo login: fixture-user / password (not for a public installation).' }
} catch {
    [Console]::Error.WriteLine('Startup failed: ' + $_.Exception.Message)
    [Console]::Error.WriteLine('No data cleanup was performed. Fix the error, then run the script again.')
    exit 1
} finally {
    Pop-Location
}
