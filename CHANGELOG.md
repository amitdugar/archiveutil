# Changelog

## v1.0.0
- Initial Packagist release of `amitdugar/archiveutil`.
- Compression backends with auto-selection: zstd → pigz/gzip → zip.
- Decompression helpers for `.zst`, `.gz`, `.zip`, and plain CSV passthrough.
- Password-protected ZIP detection and extraction (first SQL file).
- Configurable zstd level/threads, max file size guard, and directory permissions.
- PHPUnit test coverage for round-trip flows, error cases, and backend selection.
