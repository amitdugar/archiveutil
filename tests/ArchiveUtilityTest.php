<?php

declare(strict_types=1);

namespace ArchiveUtil\Tests;

use ArchiveUtil\ArchiveUtility;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use ZipArchive;

final class ArchiveUtilityTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $this->tempPaths = [];
        ArchiveUtility::clearCache();
        ArchiveUtility::setMaxFileSize(null);
        ArchiveUtility::setZstdLevel(19);
        ArchiveUtility::setZstdThreads(0);
        ArchiveUtility::setDirPermissions(0777);
    }

    protected function tearDown(): void
    {
        ArchiveUtility::clearCache();
        foreach (array_reverse($this->tempPaths) as $path) {
            $this->cleanupPath($path);
        }
    }

    public function testGzipRoundTrip(): void
    {
        if (!$this->commandExists('gzip')) {
            $this->markTestSkipped('gzip is not available on this system.');
        }

        $dir = $this->createTempDir();
        $src = $this->writeTempFile($dir, 'data.csv', "a,b,c\n1,2,3\n");

        $compressed = ArchiveUtility::compressFile($src, $dir . '/data.csv', ArchiveUtility::BACKEND_GZIP);
        $this->assertFileExists($compressed);
        $this->assertStringEndsWith('.gz', $compressed);

        $this->assertSame("a,b,c\n1,2,3\n", ArchiveUtility::decompressToString($compressed));
    }

    public function testZstdRoundTrip(): void
    {
        if (!$this->commandExists('zstd')) {
            $this->markTestSkipped('zstd is not available on this system.');
        }

        $dir = $this->createTempDir();
        $src = $this->writeTempFile($dir, 'data.csv', "zstd content\n");

        $compressed = ArchiveUtility::compressFile($src, $dir . '/data.csv', ArchiveUtility::BACKEND_ZSTD);
        $this->assertFileExists($compressed);
        $this->assertStringEndsWith('.zst', $compressed);

        $this->assertSame("zstd content\n", ArchiveUtility::decompressToString($compressed));
    }

    public function testZipRoundTrip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension missing.');
        }

        $dir = $this->createTempDir();
        $src = $this->writeTempFile($dir, 'data.csv', "zip round trip\n");

        $compressed = ArchiveUtility::compressFile($src, $dir . '/data.csv', ArchiveUtility::BACKEND_ZIP);
        $this->assertFileExists($compressed);
        $this->assertStringEndsWith('.zip', $compressed);

        $extracted = ArchiveUtility::decompressToFile($compressed, $this->createTempDir());
        $this->assertSame("zip round trip\n", file_get_contents($extracted));
    }

    public function testPasswordProtectedZipExtraction(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension missing.');
        }

        $password = 'secret';
        $dir = $this->createTempDir();
        $sqlPath = $this->writeTempFile($dir, 'protected.sql', "top secret\n");
        $zipPath = $dir . '/protected.zip';

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            $this->markTestSkipped('Unable to create zip archive for test.');
        }

        $zip->addFile($sqlPath, 'protected.sql');
        if (!$zip->setEncryptionName('protected.sql', ZipArchive::EM_AES_256, $password)) {
            $zip->close();
            $this->markTestSkipped('Zip encryption not supported in this environment.');
        }

        $zip->close();

        $this->assertTrue(ArchiveUtility::isPasswordProtected($zipPath));
        $this->assertTrue(ArchiveUtility::validateArchive($zipPath));

        $extracted = ArchiveUtility::extractPasswordProtectedZip($zipPath, $password, $this->createTempDir());
        $this->assertSame("top secret\n", file_get_contents($extracted));
    }

    public function testPlainCsvPassthrough(): void
    {
        $dir = $this->createTempDir();
        $csvPath = $this->writeTempFile($dir, 'plain.csv', "plain content\n");

        $copiedPath = ArchiveUtility::decompressToFile($csvPath, $this->createTempDir());
        $this->assertSame("plain content\n", file_get_contents($copiedPath));
        $this->assertSame("plain content\n", ArchiveUtility::decompressToString($csvPath));
    }

    public function testFindAndDecompressArchivePrefersCompressed(): void
    {
        if (!$this->commandExists('gzip')) {
            $this->markTestSkipped('gzip is not available on this system.');
        }

        $dir = $this->createTempDir();
        $csvPath = $this->writeTempFile($dir, 'data.csv', "compressed preferred\n");

        ArchiveUtility::compressFile($csvPath, $dir . '/data.csv', ArchiveUtility::BACKEND_GZIP);
        $result = ArchiveUtility::findAndDecompressArchive($dir, 'data.csv');

        $this->assertSame("compressed preferred\n", $result);
    }

    public function testAutoBackendSelectionRespectsPriority(): void
    {
        $this->withCommandCache(
            ['zstd' => true, 'pigz' => true, 'gzip' => true],
            function (): void {
                $this->assertSame(ArchiveUtility::BACKEND_ZSTD, ArchiveUtility::pickBestBackend());
            }
        );

        $this->withCommandCache(
            ['zstd' => false, 'pigz' => true, 'gzip' => true],
            function (): void {
                $this->assertSame(ArchiveUtility::BACKEND_PIGZ, ArchiveUtility::pickBestBackend());
            }
        );

        $this->withCommandCache(
            ['zstd' => false, 'pigz' => false, 'gzip' => true],
            function (): void {
                $this->assertSame(ArchiveUtility::BACKEND_GZIP, ArchiveUtility::pickBestBackend());
            }
        );
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        ArchiveUtility::decompressToFile('/no/such/file.zip', $this->createTempDir());
    }

    public function testUnsupportedExtensionThrows(): void
    {
        $dir = $this->createTempDir();
        $path = $this->writeTempFile($dir, 'file.txt', 'noop');

        $this->expectException(RuntimeException::class);
        ArchiveUtility::decompressToString($path);
    }

    public function testBadZipFailsValidationAndExtraction(): void
    {
        $dir = $this->createTempDir();
        $zipPath = $this->writeTempFile($dir, 'broken.zip', 'not a zip');

        $this->assertFalse(ArchiveUtility::validateArchive($zipPath));

        $this->expectException(RuntimeException::class);
        ArchiveUtility::decompressToFile($zipPath, $this->createTempDir());
    }

    public function testMaxFileSizeGuardBlocksCompression(): void
    {
        $dir = $this->createTempDir();
        $path = $this->writeTempFile($dir, 'large.csv', str_repeat('a', 16));

        ArchiveUtility::setMaxFileSize(1);

        $this->expectException(RuntimeException::class);
        try {
            ArchiveUtility::compressFile($path, $dir . '/large.csv', ArchiveUtility::BACKEND_ZIP);
        } finally {
            ArchiveUtility::setMaxFileSize(null);
        }
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'archiveutil_' . uniqid('', true);
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->fail('Failed to create temporary directory for tests.');
        }

        $this->tempPaths[] = $dir;
        return $dir;
    }

    private function writeTempFile(string $dir, string $filename, string $content): string
    {
        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($path, $content) === false) {
            $this->fail('Failed to write temporary file: ' . $path);
        }

        return $path;
    }

    private function cleanupPath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function commandExists(string $command): bool
    {
        $output = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($output) && trim($output) !== '';
    }

    /**
     * Temporarily overrides the internal command availability cache for testing.
     *
     * @param array<string, bool> $cache
     * @param callable $callback
     */
    private function withCommandCache(array $cache, callable $callback): void
    {
        $ref = new ReflectionClass(ArchiveUtility::class);
        $property = $ref->getProperty('cmdCache');
        $property->setAccessible(true);

        $original = $property->getValue();
        $property->setValue(null, $cache);

        try {
            $callback();
        } finally {
            $property->setValue(null, $original);
        }
    }
}
