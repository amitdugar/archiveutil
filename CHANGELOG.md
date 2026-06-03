# Changelog

## v2.0.0
- **BREAKING:** Raised the minimum PHP version to 8.4 (now supports PHP 8.4 – 8.5).
- **BREAKING:** Bumped `symfony/process` to `^8.1`.
- Upgraded the test toolchain to `phpunit/phpunit` `^12.0`.
- CI matrix updated to PHP 8.4 and 8.5, now running the test suite.

## v1.0.0
- Initial Packagist release of `amitdugar/archiveutil`.
- Compression backends with auto-selection: zstd → pigz/gzip → zip.
- Decompression helpers for `.zst`, `.gz`, `.zip`, and plain CSV passthrough.
- Password-protected ZIP detection and extraction (first SQL file).
- Configurable zstd level/threads, max file size guard, and directory permissions.
- PHPUnit test coverage for round-trip flows, error cases, and backend selection.
