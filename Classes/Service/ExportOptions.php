<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Service;

/**
 * Immutable value object that carries all parameters for a single news export run.
 *
 * Use {@see self::fromArray()} to create a validated instance from raw form
 * POST data or CLI option arrays. All validation and sanitisation happens in
 * that factory method so that the rest of the codebase can rely on the
 * properties being safe and well-typed.
 */
final class ExportOptions
{
    /**
     * @param list<positive-int> $pids            Storage PIDs whose news records should be exported.
     * @param list<positive-int> $uids            Specific news UIDs to export (overrides PID selection when non-empty).
     * @param string             $fileType        t3d file type: 'xml', 't3d', or 't3d_compressed'.
     * @param string             $fieldMap        Name of the YAML field-mapping config to apply (empty = none).
     * @param bool               $includeFiles    Whether to embed FAL file binaries inside the .t3d archive.
     * @param string             $title           Human-readable title stored in the t3d metadata header.
     * @param bool               $excludeDisabled Exclude hidden/disabled records (hidden=1) from the export.
     *                                            Note: deleted=1 records are always excluded by EXT:impexp
     *                                            regardless of this flag.
     */
    public function __construct(
        public readonly array  $pids            = [],
        public readonly array  $uids            = [],
        public readonly string $fileType        = 'xml',
        public readonly string $fieldMap        = '',
        public readonly bool   $includeFiles    = false,
        public readonly string $title           = '',
        public readonly bool   $excludeDisabled = false,
    ) {}

    /**
     * Create an {@see ExportOptions} instance from a raw associative array.
     *
     * All values are validated and sanitised:
     * - PIDs / UIDs are coerced to positive integers; zero/negative values are discarded.
     * - fileType is restricted to the three allowed literals.
     * - fieldMap is stripped of any characters that could produce a path-traversal sequence.
     * - title is stripped of HTML tags and truncated to 255 characters.
     *
     * @param array<string, mixed> $data Raw input data (e.g. $_POST or CLI options).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pids: self::parsePositiveIntList($data['pids'] ?? []),
            uids: self::parsePositiveIntList($data['uids'] ?? []),
            fileType: self::validateFileType((string)($data['fileType'] ?? 'xml')),
            fieldMap: self::sanitiseIdentifier((string)($data['fieldMap'] ?? '')),
            includeFiles: (bool)($data['includeFiles'] ?? false),
            title: mb_substr(strip_tags((string)($data['title'] ?? '')), 0, 255),
            excludeDisabled: (bool)($data['excludeDisabled'] ?? false),
        );
    }

    /**
     * Return true when no PIDs and no UIDs are set, meaning there is nothing to export.
     */
    public function isEmpty(): bool
    {
        return $this->pids === [] && $this->uids === [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a scalar value or an array of scalars to a list of positive integers.
     *
     * Zero and negative values are silently discarded.
     *
     * @param mixed $raw Scalar int, string, or array thereof.
     * @return list<positive-int>
     */
    private static function parsePositiveIntList(mixed $raw): array
    {
        /** @var list<positive-int> $result */
        $result = [];

        $items = is_array($raw) ? $raw : [$raw];
        foreach ($items as $item) {
            $int = (int)$item;
            if ($int > 0) {
                $result[] = $int;
            }
        }

        return $result;
    }

    /**
     * Ensure the file type is one of the three values accepted by EXT:impexp.
     *
     * Falls back to 'xml' for any unknown value.
     */
    private static function validateFileType(string $raw): string
    {
        return in_array($raw, ['xml', 't3d', 't3d_compressed'], true) ? $raw : 'xml';
    }

    /**
     * Strip every character that is not alphanumeric, a hyphen, or an underscore.
     *
     * This prevents path-traversal attacks when the value is later used to
     * build a filesystem path to a YAML configuration file.
     */
    private static function sanitiseIdentifier(string $raw): string
    {
        return (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $raw);
    }
}
