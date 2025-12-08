# archiveutil

Smart single-file compressor/decompressor with automatic backend selection (zstd → pigz/gzip → zip) and password-protected ZIP extraction.

## Requirements

- PHP 8.2+
- Extensions: `zip`, `zlib`
- CLI tools on `PATH` for compression/decompression: `zstd` (preferred), `pigz`, `gzip`. If none are present, falls back to `ZipArchive` for `.zip`.
- Composer dependency: `symfony/process`

## Installation

```bash
composer require amitdugar/archiveutil
```

## API

### Compression
- `compressFile(string $src, string $dst, ?string $backend = null): string` — Compress a file. Adds the correct extension automatically.
- `compressContent(string $content, string $dst, ?string $backend = null): string` — Compress raw content to disk.
- `pickBestBackend(): string` — Chooses the best available backend based on installed tools.
- `extensionForBackend(string $backend): string` — Returns `.zst`, `.gz`, or `.zip`.

### Decompression
- `decompressToFile(string $src, string $dstDir): string` — Extracts an archive into a directory (supports `.zst`, `.gz`, `.zip`, or plain `.csv` passthrough).
- `decompressToString(string $src): string` — Returns decompressed contents as a string.
- `findAndDecompressArchive(string $directory, string $filename): string` — Looks for `<filename>.zst`, `.gz`, `.zip`, then plain file.
- `validateArchive(string $path): bool` — Quick integrity check (treats password-protected ZIPs as valid).
- `isPasswordProtected(string $path): bool` — Detects encrypted ZIP archives.
- `extractPasswordProtectedZip(string $zipPath, string $password, string $dstDir): string` — Extracts the first SQL file from an encrypted ZIP.

### Configuration
- `setZstdLevel(int $level): void` — Default `19`.
- `setZstdThreads(int $threads): void` — `0/-1` = auto (all cores), otherwise a positive thread count.
- `setMaxFileSize(?int $bytes): void` — Guard compress operations with a maximum size (default: unlimited).
- `setDirPermissions(int $permissions): void` — Permissions used when creating destination directories.

## Usage

```php
use ArchiveUtil\ArchiveUtility;

// Compress a CSV (auto-picks best available backend)
$compressed = ArchiveUtility::compressFile('/path/data.csv', '/path/data.csv');

// Read contents from any supported archive
$contents = ArchiveUtility::decompressToString($compressed);

// Extract to a directory
$extracted = ArchiveUtility::decompressToFile($compressed, sys_get_temp_dir());

// Find <dir>/file.csv.{zst|gz|zip} (or plain file) and return contents
$raw = ArchiveUtility::findAndDecompressArchive('/path/dir', 'file.csv');

// Validate archive health (encrypted ZIPs count as valid)
$isValid = ArchiveUtility::validateArchive($compressed);

// Tuning
ArchiveUtility::setMaxFileSize(25 * 1024 * 1024); // 25MB guard
ArchiveUtility::setZstdLevel(19);
ArchiveUtility::setZstdThreads(0); // auto threads
```

### Password-protected ZIPs

```php
$sqlPath = ArchiveUtility::extractPasswordProtectedZip(
    '/path/dump.zip',
    'super-secret-password',
    sys_get_temp_dir()
);
```

## Testing

```bash
composer install
composer test
```

Tests isolate work in temp directories and skip backends that are not installed (e.g., `zstd` or `pigz`). No static analysis tools are bundled; add phpstan/psalm in your project if desired.

## License

MIT License (see `LICENSE`).
