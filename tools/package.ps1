# Dramatool 打包脚本（Windows PowerShell）
#
# 用法：
#   powershell -ExecutionPolicy Bypass -File tools/package.ps1
#
# 产物：dist/dramatool-<版本>.tar.gz
# 内容：可直接部署到服务器的完整项目（不含本地配置、运行时数据与开发文件）

param(
    [string]$Version = (Get-Date -Format 'yyyyMMdd-HHmmss')
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$distDir = Join-Path $root 'dist'
$stageDir = Join-Path $distDir 'dramatool'
$archive = Join-Path $distDir "dramatool-$Version.tar.gz"

Write-Host "项目根目录：$root"

# 清理并重建暂存目录
if (Test-Path $stageDir) { Remove-Item -Recurse -Force $stageDir }
New-Item -ItemType Directory -Force -Path $stageDir | Out-Null

# 需要打包的顶层内容
$include = @('app', 'config', 'public', 'sql', 'tools', 'DEPLOY.md')

# 排除规则（相对项目根目录的路径片段）
$excludeDirs = @(
    '.git', '.vscode', '.idea', 'node_modules', 'vendor', 'dist',
    'storage/sessions', 'storage/uploads', 'storage/logs', 'storage/cache', 'storage/mail'
)
$excludeFiles = @(
    'config/local.php',
    'app/blockword.txt'
)

function Test-Excluded([string]$relPath) {
    $norm = $relPath -replace '\\', '/'
    foreach ($d in $excludeDirs) {
        if ($norm -eq $d -or $norm.StartsWith("$d/")) { return $true }
    }
    foreach ($f in $excludeFiles) {
        if ($norm -eq $f) { return $true }
    }
    return $false
}

# 递归复制
foreach ($item in $include) {
    $src = Join-Path $root $item
    if (-not (Test-Path $src)) {
        Write-Warning "跳过不存在的路径：$item"
        continue
    }

    if ((Get-Item $src).PSIsContainer) {
        Get-ChildItem -Recurse -File $src | ForEach-Object {
            $rel = $_.FullName.Substring($root.Length + 1)
            if (Test-Excluded $rel) { return }
            $dest = Join-Path $stageDir $rel
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null
            Copy-Item $_.FullName $dest -Force
        }
    } else {
        Copy-Item $src (Join-Path $stageDir $item) -Force
    }
}

# 运行时目录占位（保证解压后目录存在且可写）
foreach ($d in @('storage/sessions', 'storage/uploads', 'storage/logs', 'storage/mail')) {
    $p = Join-Path $stageDir $d
    New-Item -ItemType Directory -Force -Path $p | Out-Null
    Set-Content -Path (Join-Path $p '.gitkeep') -Value '' -NoNewline
}

# 敏感词词库：源码中不纳入版本管理，打包时生成空占位文件
$bw = Join-Path $stageDir 'app/blockword.txt'
Set-Content -Path $bw -Value "# 敏感词词库：每行一个词条，支持正则；以 # 开头为注释`n" -Encoding UTF8

# 本地配置示例（若存在）
$example = Join-Path $root 'config/local.php.example'
if (Test-Path $example) {
    Copy-Item $example (Join-Path $stageDir 'config/local.php.example') -Force
}

# 打包为 tar.gz
if (Test-Path $archive) { Remove-Item -Force $archive }
Push-Location $distDir
try {
    tar -czf $archive 'dramatool'
} finally {
    Pop-Location
}

Remove-Item -Recurse -Force $stageDir

$size = [math]::Round((Get-Item $archive).Length / 1KB, 1)
Write-Host ""
Write-Host "打包完成：$archive ($size KB)"
