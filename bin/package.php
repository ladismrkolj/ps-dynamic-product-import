<?php

/**
 * Builds the installable module zip: dist/productimport-<version>.zip.
 *
 * Usage (from the repo root):
 *   php bin/package.php [--ref=HEAD] [--out=dist] [--archive=file.tar] [--composer=path]
 *
 * The git ref is exported with `git archive` (so .gitattributes export-ignore applies and
 * uncommitted changes are excluded), production Composer dependencies are installed into
 * the staging copy, and the result is zipped with a single top-level `productimport/` folder.
 * --archive=<tar> uses a tar made by `git archive --prefix=productimport/` instead of
 * calling git (for environments without git). --composer=<path> runs a composer.phar
 * with the current PHP binary; otherwise $COMPOSER or `composer` on PATH is used.
 */

declare(strict_types=1);

const ROOT_DIR = 'productimport';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

/** @return array{0: int, 1: string} exit code and combined output */
function run(string $command, ?string $cwd = null, bool $passthru = false): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($process)) {
        fail("Cannot start: $command");
    }
    $output = '';
    while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
        $output .= $chunk;
        if ($passthru) {
            fwrite(STDOUT, $chunk);
        }
    }
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }
    rmdir($path);
}

$options = getopt('', ['ref::', 'out::', 'archive::', 'composer::']);
$ref = (string) ($options['ref'] ?? 'HEAD');
$out = (string) ($options['out'] ?? 'dist');
$archive = isset($options['archive']) ? (string) $options['archive'] : null;
$composerOption = isset($options['composer']) ? (string) $options['composer'] : null;

if (!class_exists(ZipArchive::class)) {
    fail('The PHP zip extension is required.');
}
if (!class_exists(PharData::class) && $archive === null) {
    fail('The PHP phar extension is required.');
}

$repo = dirname(__DIR__);
chdir($repo);

$tmp = sys_get_temp_dir() . '/productimport-package-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true)) {
    fail("Cannot create $tmp");
}
register_shutdown_function(static fn () => removeTree($tmp));

// 1. Export the ref.
$tarPath = $tmp . '/export.tar';
if ($archive !== null) {
    if (!is_file($archive)) {
        fail("Archive not found: $archive");
    }
    $tarPath = $archive;
} else {
    [$code, $status] = run('git status --porcelain');
    if ($code === 0 && trim($status) !== '') {
        fwrite(STDERR, "WARNING: working tree is dirty; uncommitted changes are NOT in the package (building $ref).\n");
    }
    [$code, $output] = run(
        'git archive --format=tar --prefix=' . ROOT_DIR . '/ -o ' . escapeshellarg($tarPath) . ' ' . escapeshellarg($ref)
    );
    if ($code !== 0) {
        fail("git archive failed for ref '$ref': " . trim($output));
    }
}

// 2. Extract into staging.
$staging = $tmp . '/staging';
mkdir($staging);
try {
    (new PharData($tarPath))->extractTo($staging, null, true);
} catch (Throwable $e) {
    fail('Cannot extract archive: ' . $e->getMessage());
}
$module = $staging . '/' . ROOT_DIR;
if (!is_dir($module)) {
    fail('Archive has no ' . ROOT_DIR . '/ folder.');
}

// 3. Version check.
$config = @simplexml_load_file($module . '/config.xml');
$configVersion = $config === false ? '' : trim((string) $config->version);
if ($configVersion === '') {
    fail('Cannot read <version> from config.xml.');
}
if (!preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', (string) file_get_contents($module . '/productimport.php'), $m)) {
    fail('Cannot find $this->version in productimport.php.');
}
if ($m[1] !== $configVersion) {
    fail("Version mismatch: config.xml is $configVersion, productimport.php is {$m[1]}.");
}
if (!preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/', $configVersion)) {
    fail("Unexpected version format: $configVersion");
}

// 4. Production dependencies.
if (!is_file($module . '/composer.lock')) {
    fail('composer.lock is missing from the export.');
}
if ($composerOption !== null) {
    $composer = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composerOption);
} else {
    $composer = getenv('COMPOSER') ?: 'composer';
}
[$code] = run(
    $composer . ' install --no-dev --optimize-autoloader --no-interaction',
    $module,
    true
);
if ($code !== 0) {
    fail("composer install failed (exit $code). The lock's platform requirements must fit PHP 8.3.");
}
if (!is_file($module . '/vendor/autoload.php')) {
    fail('vendor/autoload.php missing after composer install.');
}

// 5. Zip.
if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    fail("Cannot create output directory $out");
}
$zipPath = rtrim(realpath($out), '/') . "/productimport-$configVersion.zip";
if (is_file($zipPath)) {
    unlink($zipPath);
}
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail("Cannot create $zipPath");
}
$count = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$prefixLength = strlen($staging) + 1;
foreach ($iterator as $file) {
    $path = $file->getPathname();
    $name = substr($path, $prefixLength);
    if ($file->isLink()) {
        continue;
    }
    if ($file->isDir()) {
        $zip->addEmptyDir($name);
        continue;
    }
    if (!$zip->addFile($path, $name)) {
        fail("Cannot add $name to the zip.");
    }
    $perms = $file->getPerms() & 0777;
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0100000 | $perms) << 16);
    ++$count;
}
if (!$zip->close()) {
    fail("Cannot write $zipPath");
}

printf("Package: %s\nSize: %d bytes\nFiles: %d\n", $zipPath, filesize($zipPath), $count);
