<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

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
        if (!is_dir($this->getClibPath())) {
            return false;
        }
        
        return true;
    }

    public function getClibPath(): string
    {
        return $this->projectRoot . '/clib';
    }

    public function build(): string
    {
        if (!$this->isBuildAvailable()) {
            throw new \RuntimeException('Build tools not available');
        }
        
        $clibPath = $this->getClibPath();
        $buildPath = $clibPath . '/build';
        
        // Create build directory
        if (!is_dir($buildPath)) {
            mkdir($buildPath, 0755, true);
        }
        
        // Run cmake
        $cmakeCmd = sprintf(
            'cd %s && cmake .. -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTS=OFF 2>&1',
            escapeshellarg($buildPath)
        );
        
        $cmakeOutput = shell_exec($cmakeCmd);
        $cmakeExitCode = 0;
        exec($cmakeCmd, $_, $cmakeExitCode);
        
        if ($cmakeExitCode !== 0) {
            throw new \RuntimeException('CMake configuration failed: ' . $cmakeOutput);
        }
        
        // Run make
        $makeCmd = sprintf(
            'cd %s && make -j$(nproc) 2>&1',
            escapeshellarg($buildPath)
        );
        
        $makeOutput = shell_exec($makeCmd);
        $makeExitCode = 0;
        exec($makeCmd, $_, $makeExitCode);
        
        if ($makeExitCode !== 0) {
            throw new \RuntimeException('Build failed: ' . $makeOutput);
        }
        
        // Find the built library
        $libraryPath = $this->findBuiltLibrary($buildPath);
        
        if ($libraryPath === null) {
            throw new \RuntimeException('Built library not found in: ' . $buildPath);
        }
        
        return $libraryPath;
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
