<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Exception\BuildException;

class Builder
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function isBuildAvailable(): bool
    {
        // Check for CMake
        $cmake = shell_exec('which cmake 2>/dev/null');
        if (empty($cmake)) {
            return false;
        }

        // Check for C++ compiler
        $cxx = shell_exec('which g++ 2>/dev/null') ?? shell_exec('which clang++ 2>/dev/null');
        if (empty($cxx)) {
            return false;
        }
        // Check for clib directory
        return is_dir($this->getClibPath());
    }

    public function getClibPath(): string
    {
        return $this->projectRoot . '/clib';
    }

    public function build(): string
    {
        if (!$this->isBuildAvailable()) {
            throw BuildException::toolsNotAvailable();
        }

        $clibPath = $this->getClibPath();
        $buildPath = $clibPath . '/build';

        // Create build directory
        if (!is_dir($buildPath)) {
            mkdir($buildPath, 0755, true);
        }

        // Run cmake (single invocation: exit code only; stderr stays on the
        // process stderr and is never forwarded to callers via exceptions)
        $cmakeCmd = sprintf(
            'cd %s && cmake .. -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTS=OFF',
            escapeshellarg($buildPath)
        );

        $cmakeExitCode = $this->runCommand($cmakeCmd);

        if ($cmakeExitCode !== 0) {
            throw BuildException::cmakeFailed($cmakeExitCode);
        }

        // Run make (single invocation; exit code only)
        $makeCmd = sprintf(
            'cd %s && make -j$(nproc)',
            escapeshellarg($buildPath)
        );

        $makeExitCode = $this->runCommand($makeCmd);

        if ($makeExitCode !== 0) {
            throw BuildException::buildFailed($makeExitCode);
        }

        // Find the built library
        $libraryPath = $this->findBuiltLibrary($buildPath);

        if ($libraryPath === null) {
            throw BuildException::libraryNotFound($buildPath);
        }

        return $libraryPath;
    }

    /**
     * Execute a command once, returning only its exit code.
     *
     * Stderr is NOT merged into the return value (no `2>&1`), so raw
     * compiler/diagnostic text containing local paths and environment
     * details is never forwarded to callers via exception messages; stderr
     * stays on the process's own stderr for CLI/CI diagnostics.
     *
     * @param string $command The command to execute (already escaped).
     * @return int The command's exit code.
     */
    private function runCommand(string $command): int
    {
        $lines = [];
        exec($command, $lines, $exitCode);

        return $exitCode;
    }

    private function findBuiltLibrary(string $buildPath): ?string
    {
        $patterns = [
            'libscanme_qr.so',
            'libscanme_qr.dylib',
            'scanme_qr.dll',
        ];

        foreach ($patterns as $pattern) {
            $path = $buildPath . '/' . $pattern;
            if (file_exists($path)) {
                return $path;
            }

            // Check Release subdirectory for Windows
            $path = $buildPath . '/Release/' . $pattern;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
