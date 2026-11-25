# Build a reviewable code package. Never includes private config, library uploads, keys or backups.
[CmdletBinding()]
param([ValidateSet('Production','Demo')][string]$Mode='Production')
$ErrorActionPreference='Stop'
$projectRoot=Split-Path -Parent $PSScriptRoot
$outputDirectory=Join-Path $projectRoot 'deploy/releases'
New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
$stamp=Get-Date -Format 'yyyyMMdd-HHmmss'
$archivePath=Join-Path $outputDirectory ("booktrack-$($Mode.ToLower())-$stamp-$([Guid]::NewGuid().ToString('N').Substring(0,8)).zip")
$paths=New-Object 'System.Collections.Generic.List[string]'
foreach($file in Get-ChildItem -LiteralPath $projectRoot -File){
    if($file.Name -in @('.htaccess','LICENSE','README.md','HARDENING.md','DEPLOY.md','SECURITY.md','config.production.example.php','config.local.example.php') -or ($file.Extension -eq '.php' -and $file.Name -notin @('config.local.php','setup.php','config.production.example.php','config.local.example.php'))){$paths.Add($file.FullName)}
}
foreach($directory in @('admin','api','assets','includes','cron','tools')){
    foreach($file in Get-ChildItem -LiteralPath (Join-Path $projectRoot $directory) -Recurse -File){
        if($file.Attributes -band [System.IO.FileAttributes]::ReparsePoint){throw 'Linked files are not allowed in a release.'}
        if($file.Name -eq 'build-release.ps1' -or $file.Extension -in @('.log','.sqlite','.key','.btenc','.tar','.zip')){continue}
        $paths.Add($file.FullName)
    }
}
foreach($directory in @('storage','uploads')){
    foreach($file in Get-ChildItem -LiteralPath (Join-Path $projectRoot $directory) -Recurse -File){if($file.Name -in @('.htaccess','.gitkeep')){$paths.Add($file.FullName)}}
}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$stream=[System.IO.File]::Open($archivePath,[System.IO.FileMode]::CreateNew)
$archive=New-Object System.IO.Compression.ZipArchive($stream,[System.IO.Compression.ZipArchiveMode]::Create,$false)
$manifest=New-Object 'System.Collections.Generic.List[object]'
$sha=[System.Security.Cryptography.SHA256]::Create()
try{
    foreach($path in ($paths | Sort-Object -Unique)){
        $relative=$path.Substring($projectRoot.Length+1).Replace('\','/')
        if($relative -match '(^|/)(config\.local\.php|\.env[^/]*|\.git|_backups|tests|visual-review|deploy)(/|$)'){throw "Private path rejected: $relative"}
        $bytes=[System.IO.File]::ReadAllBytes($path)
        if($relative -eq 'config.php'){
            $text=[System.Text.Encoding]::UTF8.GetString($bytes)
            $insertion=if($Mode -eq 'Production'){"return [`n    'environment' => 'production',"}else{"return [`n    'environment' => 'demo',"}
            $text=[regex]::Replace($text,'return \[',[System.Text.RegularExpressions.MatchEvaluator]{param($match) $insertion},1)
            if($Mode -eq 'Demo'){$text=$text.Replace("'debug' => true","'debug' => false").Replace("'enabled'     => false","'enabled'     => true").Replace("'driver'             => 'log'","'driver'             => 'off'")}
            $bytes=[System.Text.Encoding]::UTF8.GetBytes($text)
        }
        $entry=$archive.CreateEntry($relative,[System.IO.Compression.CompressionLevel]::Optimal);$entryStream=$entry.Open()
        try{$entryStream.Write($bytes,0,$bytes.Length)}finally{$entryStream.Dispose()}
        $digest=([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-','').ToLowerInvariant()
        $manifest.Add([ordered]@{path=$relative;bytes=$bytes.Length;sha256=$digest})
    }
    $metadata=[ordered]@{format='booktrack-code-release';version=1;mode=$Mode;created_at=(Get-Date).ToUniversalTime().ToString('o');private_data_included=$false;installer_included=$false;files=$manifest}
    $manifestBytes=[System.Text.Encoding]::UTF8.GetBytes(($metadata | ConvertTo-Json -Depth 6))
    $entry=$archive.CreateEntry('release-manifest.json');$entryStream=$entry.Open();try{$entryStream.Write($manifestBytes,0,$manifestBytes.Length)}finally{$entryStream.Dispose()}
}finally{$archive.Dispose();$stream.Dispose();$sha.Dispose()}
$digest=(Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
[ordered]@{archive=$archivePath;sha256=$digest;mode=$Mode;files=$manifest.Count;private_data_included=$false;next='Review DEPLOY.md and configure the target host before publishing.'} | ConvertTo-Json
