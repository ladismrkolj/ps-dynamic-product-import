<?php

namespace ProductImport\Service;

use ProductImport\Repository\ImportRunRepository;

class BackgroundImport
{
    public static function selectionArgument($selection): string
    {
        if ($selection === 'all') {
            return 'all';
        }
        if (!is_int($selection) || $selection <= 0) {
            throw new \InvalidArgumentException('Selection must be all or a positive source ID.');
        }
        return (string) $selection;
    }

    public static function buildCommand(string $php, string $script, $selection, string $triggeredBy, string $logFile, bool $nice = false): string
    {
        // bin/import.php expects selection first, then the original trigger label.
        $argument = self::selectionArgument($selection);
        return 'nohup ' . ($nice ? 'nice -n 10 ' : '') . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($argument) . ' ' . escapeshellarg($triggeredBy) . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
    }

    public function start($selection, string $triggeredBy): array
    {
        self::selectionArgument($selection);
        $php = PHP_BINDIR . '/php';
        $script = dirname(__DIR__, 2) . '/bin/import.php';
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (PHP_OS_FAMILY === 'Windows' || !is_executable($php) || !function_exists('exec') || in_array('exec', $disabled, true)) {
            throw new BackgroundImportUnsupportedException('Background process spawning is unavailable.');
        }
        if (!self::acquireStartingMarker()) {
            throw new ImportLockBusyException('Import already running or lock unavailable');
        }
        try {
            if (ImportLauncher::isRunning()) {
                throw new ImportLockBusyException('Import already running or lock unavailable');
            }
            $baseline = (new ImportRunRepository())->maxId();
            $log = _PS_CACHE_DIR_ . 'productimport-run-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.log';
            $nice = is_executable('/usr/bin/nice') || is_executable('/bin/nice');
            $command = self::buildCommand($php, $script, $selection, $triggeredBy, $log, $nice);
            exec($command, $output, $status);
            if ($status !== 0) {
                throw new BackgroundImportUnsupportedException('Unable to start background import.');
            }
        } catch (\Throwable $error) {
            // The spawned process (if any) clears the marker itself once it holds the import lock.
            self::clearStartingMarker();
            throw $error;
        }
        return ['started' => true, 'baseline_run_id' => $baseline];
    }

    public const STARTING_MARKER_TTL = 60;

    public static function isMarkerStale(int $modifiedAt, int $now): bool
    {
        return $now - $modifiedAt > self::STARTING_MARKER_TTL;
    }

    private static function markerPath(): string
    {
        return _PS_CACHE_DIR_ . 'productimport-starting.marker';
    }

    /** Exclusive create: of two simultaneous starters only one succeeds. */
    private static function acquireStartingMarker(): bool
    {
        $path = self::markerPath();
        for ($try = 0; $try < 2; ++$try) {
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                fclose($handle);
                return true;
            }
            $modifiedAt = @filemtime($path);
            if ($modifiedAt === false || !self::isMarkerStale($modifiedAt, time())) {
                return false;
            }
            @unlink($path);
        }

        return false;
    }

    /** Called by the import process once it holds the lock (and after failed starts). */
    public static function clearStartingMarker(): void
    {
        @unlink(self::markerPath());
    }
}
