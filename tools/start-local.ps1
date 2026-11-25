# Start/check an existing XAMPP installation. Never installs or resets a database.
[CmdletBinding()]
param(
    [switch]$CheckOnly,
    [string]$XamppRoot = '',
    [int]$ApachePort = 80,
    [int]$MySqlPort = 3306
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($XamppRoot)) { $XamppRoot = Split-Path -Parent (Split-Path -Parent $projectRoot) }
$projectName = Split-Path -Leaf $projectRoot
function Test-LocalPort([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $connecting = $client.ConnectAsync('127.0.0.1', $Port)
        return ($connecting.Wait(1000) -and $client.Connected)
    } catch { return $false } finally { $client.Dispose() }
}
function Start-LocalService([string]$Name, [string]$Executable, [string]$WorkingDirectory, [string[]]$ServiceArguments, [int]$Port) {
    if (Test-LocalPort $Port) { Write-Host "$Name is listening on port $Port."; return }
    if ($CheckOnly) { throw "$Name is not listening on port $Port. Run this script without -CheckOnly, or start it in XAMPP." }
    if (!(Test-Path -LiteralPath $Executable)) { throw "$Name was not found at $Executable. Set -XamppRoot to your XAMPP folder." }
    if (Get-Process -Name ([System.IO.Path]::GetFileNameWithoutExtension($Executable)) -ErrorAction SilentlyContinue) {
        throw "$Name has a process but is not listening on the expected port. Check its XAMPP log before starting another instance."
    }
    Start-Process -FilePath $Executable -ArgumentList $ServiceArguments -WorkingDirectory $WorkingDirectory -WindowStyle Hidden | Out-Null
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if (Test-LocalPort $Port) { Write-Host "$Name started on port $Port."; return }
        Start-Sleep -Milliseconds 250
    }
    throw "$Name did not start. Check the XAMPP control panel and logs in $XamppRoot."
}
try {
    Start-LocalService -Name 'MySQL' -Executable "$XamppRoot/mysql/bin/mysqld.exe" -WorkingDirectory "$XamppRoot/mysql/bin" -ServiceArguments @("--defaults-file=`"$XamppRoot/mysql/bin/my.ini`"", '--standalone') -Port $MySqlPort
    Start-LocalService -Name 'Apache' -Executable "$XamppRoot/apache/bin/httpd.exe" -WorkingDirectory "$XamppRoot/apache/bin" -ServiceArguments @('-d', "`"$XamppRoot/apache`"") -Port $ApachePort
    $portSuffix = if ($ApachePort -eq 80) { '' } else { ":$ApachePort" }
    $bookTrackUrl = "http://localhost$portSuffix/$projectName/login.php"
    $response = Invoke-WebRequest -Uri $bookTrackUrl -UseBasicParsing -TimeoutSec 15
    if ($response.Content -notmatch 'BookTrack') { throw 'The web server responded, but this project was not found at the expected URL.' }
    Write-Host "BookTrack is ready: $bookTrackUrl"
    Write-Host 'Keep Apache and MySQL running while using BookTrack.'
} catch {
    Write-Error -Message ($_.Exception.Message + ' No database was created, imported or reset.') -ErrorAction Continue
    exit 1
}
