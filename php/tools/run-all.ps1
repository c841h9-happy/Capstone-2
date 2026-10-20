# Every test this project has, in one command.
#
#     pwsh -File php\tools\run-all.ps1
#     pwsh -File php\tools\run-all.ps1 -Quick          # the PHP suites only, no browser
#     pwsh -File php\tools\run-all.ps1 -AdminPassword 'xxx'
#
# WHY THIS FILE EXISTS. Until 2026-10-02 there was no single command that ran the suites. Each was
# its own php.exe invocation with its own full path, the naming is inconsistent (five end in
# -test.php and three do not), and a runner that silently reports NOT FOUND for a mistyped name looks
# exactly like a pass in a summary table. This prints the path it tried.
#
# NOTE ON INVOKING THIS. pwsh.exe is NOT at C:\Program Files\PowerShell\7\pwsh.exe on this machine,
# so the `pwsh -File` lines above are the documented form rather than one that is known to work
# here. Call the script directly instead:
#
#     & 'C:\xampp\htdocs\Capstone\php\tools\run-all.ps1'
#
# ADOPT-OR-START, and the three rules under it are not optional. A server started from a tool call
# dies with the shell's process tree, so start / work / shut down has to happen inside ONE call --
# on 2026-09-16 a killed mysqld corrupted the redo log and MariaDB would not start at all.
#
#   1. Refuse to start while any mysqld exists. On 2026-09-27 a shutdown returned success and left
#      the process alive and wedged; the next run's probe failed against it and started a SECOND
#      mysqld on the same data directory, which produced OS error 203 and an assert, and surfaced in
#      PHP as "server has gone away" mid-suite. Nothing was corrupted and the run was lost.
#   2. Readiness is a real SELECT 1, never mysqladmin ping. A wedged instance answers ping.
#   3. After shutdown, poll until Get-Process mysqld is actually empty before returning.
#
# And stop Apache with Stop-Process, never httpd.exe -k stop: -k signals a service or an instance
# installed by -k start, and against a plain Start-Process instance it exits 0 and leaves httpd
# running. The orphan holds port 80, so the XAMPP panel shows Apache stopped while Start fails.
#
# If the stack was already up it belongs to whoever started it: this adopts it and leaves it
# running. It only ever stops what it started itself.

[CmdletBinding()]
param(
    # Skip the browser half. The PHP suites take seconds; the browser suites take minutes.
    [switch]$Quick,

    [string]$AdminEmail = 'admin@capstone.test',

    # Only ever passed to the browser suites, which need to sign in as the shop through the real
    # form. Not echoed, and not written to the summary.
    [string]$AdminPassword = 'Admin123!'
)

$ErrorActionPreference = 'Continue'

$php        = 'C:\xampp\php\php.exe'
$node       = 'C:\Program Files\nodejs\node.exe'
$mysqld     = 'C:\xampp\mysql\bin\mysqld.exe'
$mysql      = 'C:\xampp\mysql\bin\mysql.exe'
$mysqladmin = 'C:\xampp\mysql\bin\mysqladmin.exe'
$httpd      = 'C:\xampp\apache\bin\httpd.exe'

# This file lives in php\tools, so every suite beside it is one Join-Path away. Resolved from the
# script's own location rather than from the working directory, so the runner works from anywhere.
$tools = Split-Path -Parent $MyInvocation.MyCommand.Path

function Test-DbReally {
    $out = & $mysql -u root --batch --skip-column-names -e 'SELECT 1' capstone 2>&1
    return ($LASTEXITCODE -eq 0 -and "$out".Trim() -eq '1')
}

function Test-WebReally {
    try {
        $r = Invoke-WebRequest -Uri 'http://localhost/Capstone/php/index.php' -UseBasicParsing `
                 -TimeoutSec 5 -SkipHttpErrorCheck -MaximumRedirection 0
        return ($null -ne $r.StatusCode)
    } catch { return $false }
}

function Write-Head([string]$text) {
    Write-Output ''
    Write-Output "=== $text ==="
}

# --- the tools themselves exist ------------------------------------------------------------

Write-Head 'the tools'
$missing = 0
foreach ($need in @($php, $node, $mysqld, $mysql, $mysqladmin, $httpd)) {
    if (-not (Test-Path $need)) { Write-Output "  MISSING  $need"; $missing++ }
}
if ($missing -gt 0) { Write-Output "$missing tool(s) missing -- nothing was run."; exit 2 }
Write-Output '  php, node, mysqld, mysql, mysqladmin, httpd all present'

# --- the stack ------------------------------------------------------------------------------

if (@(Get-Process mysqld -ErrorAction SilentlyContinue)) {
    if (-not (Test-DbReally)) {
        Write-Output 'DB: FATAL -- a mysqld process exists but will not answer SELECT 1.'
        Write-Output '    That is the wedged state. Refusing to start a second one on the same data directory.'
        exit 2
    }
    Write-Output 'DB: adopting the running server.'
    $startedDb = $false
} else {
    Start-Process -FilePath $mysqld -ArgumentList '--console' -WindowStyle Hidden
    $startedDb = $true
    $ok = $false
    foreach ($i in 1..40) { Start-Sleep -Milliseconds 750; if (Test-DbReally) { $ok = $true; break } }
    if (-not $ok) { Write-Output 'DB: FATAL -- never answered SELECT 1.'; exit 2 }
    Write-Output 'DB: up, SELECT 1 answered.'
}

if (Test-WebReally) {
    Write-Output 'WEB: adopting the running server.'
    $startedWeb = $false
} else {
    Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Process -FilePath $httpd -WindowStyle Hidden
    $startedWeb = $true
    $ok = $false
    foreach ($i in 1..30) { Start-Sleep -Milliseconds 600; if (Test-WebReally) { $ok = $true; break } }
    Write-Output "WEB: $(if ($ok) { 'up, the site is answering.' } else { 'FATAL -- no answer.' })"
}

# --- counts, so the run can prove it put the shop back ---------------------------------------

$COUNTS = @'
SELECT (SELECT COUNT(*) FROM product_components) AS parts,
       (SELECT COUNT(*) FROM stock_moves)        AS moves,
       (SELECT COUNT(*) FROM product_parts)      AS recipe_rows,
       (SELECT COALESCE(SUM(stock_on_hand),0) FROM product_components) AS units,
    (SELECT COUNT(*) FROM materials)          AS materials,
    (SELECT COUNT(*) FROM material_moves)     AS material_moves,
    (SELECT COUNT(*) FROM product_materials)  AS material_recipes,
    (SELECT COALESCE(SUM(stock_on_hand),0) FROM materials) AS material_units,
       (SELECT COUNT(*) FROM products)    AS products,
       (SELECT COUNT(*) FROM orders)      AS orders,
       (SELECT COUNT(*) FROM order_items) AS order_items,
       (SELECT COUNT(*) FROM carts)       AS carts,
       (SELECT COUNT(*) FROM cart_items)  AS cart_items,
       (SELECT COUNT(*) FROM saved_designs) AS designs,
       (SELECT COUNT(*) FROM users)       AS users
'@

Write-Head 'counts before'
& $mysql -u root --batch -e $COUNTS capstone

$summary = @()

function Run-Suite([string]$name, [string]$exe, [string[]]$arguments, [string]$path) {
    Write-Output ''
    Write-Output "--- $name ---"

    if (-not (Test-Path $path)) {
        # Named rather than skipped silently. A runner that reports nothing for a file it could not
        # find reads as a pass in the summary, which is the one thing a summary must never do.
        Write-Output "  NOT FOUND  $path"
        $script:summary += [pscustomobject]@{ suite = $name; exit = 127; result = "NOT FOUND: $path" }
        return
    }

    $out = & $exe @arguments 2>&1
    $code = $LASTEXITCODE

    # Only the lines carrying a verdict, so ten suites fit on one screen. Failures print in full.
    $out | Select-String -Pattern 'passed, \d+ failed|checks, \d+ (skipped, )?(all green|\d+ FAILED)|leftover|FAIL|SKIP|MISSING' |
        ForEach-Object { "  $($_.Line.Trim())" }

    $line = ($out | Select-String -Pattern 'passed, \d+ failed|checks, \d+ skipped' | Select-Object -Last 1)

    $script:summary += [pscustomobject]@{
        suite  = $name
        exit   = $code
        result = if ($line) { $line.Line.Trim() } else { 'no result line' }
    }
}

# --- the browser JavaScript actually parses ----------------------------------------------------
#
# WHY THIS IS A STEP AND NOT AN AFTERTHOUGHT. On 2026-10-03 a duplicate top-level `const` went into
# js/customizer.js. That is a SyntaxError for the WHOLE module, so the file never evaluated and
# every flower shape fell back to the written summary -- and php/customize.php is built to survive
# exactly that silently, with one fallback for no JavaScript and one for no WebGL. The symptom was
# a canvas that stayed hidden, which reads like a machine with no GPU.
#
# Three things passed it: `node --check js/customizer.js`, a PHP suite doing str_contains() over
# the source, and a standalone preview page that carried its own copy of the geometry. The first is
# the trap worth naming here:
#
#   node --check x.js   parses as a CommonJS SCRIPT and exits 0 on a duplicate top-level const
#   node --check x.mjs  parses as an ES MODULE and fails correctly
#
# js/customizer.js is an ES module with a .js extension, so the obvious check is the wrong parser.
# Copying the bytes to a .mjs is what makes the check real.

Write-Head 'the browser JavaScript parses as a module'

$jsBad = 0
foreach ($js in @('customizer.js', 'script.js')) {
    $source = Join-Path (Split-Path -Parent (Split-Path -Parent $tools)) "js\$js"

    if (-not (Test-Path $source)) {
        Write-Output "  NOT FOUND  $source"
        $jsBad++
        continue
    }

    # A .mjs copy, because the extension is what picks the parser.
    $probe = Join-Path ([System.IO.Path]::GetTempPath()) ("runall-" + ($js -replace '\.js$', '') + ".mjs")
    Copy-Item $source $probe -Force

    $out = & $node --check $probe 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Output "  ok    js\$js parses as an ES module"
    } else {
        $jsBad++
        Write-Output "  FAIL  js\$js does NOT parse as an ES module"
        $out | ForEach-Object { Write-Output "          $_" }
    }

    Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue
}

$summary += [pscustomobject]@{
    suite  = 'js-parse'
    exit   = $jsBad
    result = "$jsBad file(s) failed to parse as a module"
}

# --- the ten PHP suites ---------------------------------------------------------------------
#
# The naming is inconsistent and guessing costs a whole run: seven end in -test.php and three do not.

Write-Head 'the PHP suites'

foreach ($suite in @(
    @('cart-unit',      'cart-unit.php'),
    @('cart-http',      'cart-http-test.php'),
    @('orders-unit',    'orders-unit.php'),
    @('orders-http',    'orders-http-test.php'),
    @('csrf-http',      'csrf-http-test.php'),
    @('admin-http',     'admin-http-test.php'),
    @('inventory-http', 'inventory-http-test.php'),
    @('shapes-unit',    'shapes-unit.php'),
    @('events-http',    'events-http-test.php'),
    @('settings-http',  'settings-http-test.php'),
    @('security-http',  'security-http-test.php')
)) {
    $path = Join-Path $tools $suite[1]
    Run-Suite $suite[0] $php @($path) $path
}

# --- the three browser suites ------------------------------------------------------------------

if ($Quick) {
    Write-Head 'the browser suites'
    Write-Output '  skipped (-Quick)'
} else {
    Write-Head 'the browser suites'

    foreach ($suite in @(
        @('layout',       'layout-test.mjs',       $true),
        @('journey',      'journey-test.mjs',      $true),
        @('csrf-browser', 'csrf-browser-test.mjs', $false)
    )) {
        $path = Join-Path $tools $suite[1]
        $arguments = if ($suite[2]) { @($path, $AdminEmail, $AdminPassword) } else { @($path) }
        Run-Suite $suite[0] $node $arguments $path
    }
}

# --- the verdict --------------------------------------------------------------------------------

Write-Head 'summary'
$summary | ForEach-Object { "{0,-16} exit {1,-4} {2}" -f $_.suite, $_.exit, $_.result }
$failed = @($summary | Where-Object { $_.exit -ne 0 }).Count
Write-Output "failed suites: $failed"

Write-Head 'counts after'
& $mysql -u root --batch -e $COUNTS capstone

Write-Head 'anything ZZ left behind'
& $mysql -u root --batch -e @'
SELECT (SELECT COUNT(*) FROM product_components WHERE name LIKE 'ZZ%') AS zz_parts,
       (SELECT COUNT(*) FROM products WHERE name LIKE 'ZZ%')           AS zz_products,
       (SELECT COUNT(*) FROM stock_moves WHERE component_name LIKE 'ZZ%') AS zz_moves,
       (SELECT COUNT(*) FROM users WHERE email LIKE 'zztest%')          AS zz_users,
       (SELECT COUNT(*) FROM orders WHERE contact_name LIKE 'ZZ%')     AS zz_orders,
       (SELECT COUNT(*) FROM saved_designs WHERE name LIKE 'ZZ%')      AS zz_designs,
       (SELECT COUNT(*) FROM events WHERE name LIKE 'ZZ%')             AS zz_events,
       (SELECT COUNT(*) FROM settings WHERE name LIKE 'zztest.%')      AS zz_settings,
       (SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'zztest%') AS zz_logins
'@ capstone

# WHY THOSE LAST THREE WERE ADDED (2026-10-15). The counter above is the only thing that notices a
# suite stranding a row, and for a while it did not cover three tables the suites write to -- events
# (events-http), settings (settings-http) and login_attempts (security-http, plus every suite that
# signs in). This file's own lesson, learned when a leftover total missed cart_items: a total that
# does not cover every table the suites touch reports 0 and means nothing.
#
# A note on zz_logins specifically, so a non-zero reading is not misread as a stranded row. Every
# successful sign-in writes a `login_attempts` row by design -- that is what clears an identifier's
# failures -- so a fixture that signs in legitimately leaves one behind, and recordLoginAttempt()
# prunes it when it ages past LOGIN_WINDOW_SECONDS. Only FAILURES throttle anybody, and a success
# row throttles nothing. A few here is the system working; a pile of them with succeeded = 0 would
# be a suite that locked an account out and did not clean up.

# --- put the stack back exactly as it was found ---------------------------------------------------

Write-Output ''
if ($startedWeb) {
    Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Sleep -Milliseconds 500
    Write-Output "WEB: stopped ($(@(Get-Process httpd -ErrorAction SilentlyContinue).Count) httpd left)."
} else {
    Write-Output 'WEB: left running -- it was not ours.'
}

if ($startedDb) {
    & $mysqladmin -u root shutdown 2>&1 | Out-Null
    foreach ($i in 1..60) {
        if (-not @(Get-Process mysqld -ErrorAction SilentlyContinue)) { break }
        Start-Sleep -Milliseconds 500
    }
    Write-Output "DB: stopped, $(@(Get-Process mysqld -ErrorAction SilentlyContinue).Count) mysqld left."
} else {
    Write-Output 'DB: left running -- it was not ours.'
}

exit $(if ($failed -eq 0) { 0 } else { 1 })
