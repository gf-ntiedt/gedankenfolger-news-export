<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Gedankenfolger\GedankenfolgerNewsExport\FieldMapper\NewsFieldMapper;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Impexp\Export;

/**
 * Core service that builds and executes a .t3d export for georgringer/news records.
 *
 * ## Responsibilities
 * 1. Configure a {@see Export} instance with the correct tables and relation rules.
 * 2. Run the EXT:impexp export pipeline (`process()` + `render()`).
 * 3. Optionally apply a field-mapping transformation via {@see NewsFieldMapper}.
 * 4. Return the final XML string to the caller (controller or CLI command).
 *
 * ## Why we return a string and not a File object
 * Returning the raw XML string keeps the service free of filesystem side-effects
 * and lets each caller decide how to deliver the content – as a streamed HTTP
 * download (backend module) or as a file written to a user-specified path (CLI).
 *
 * ## Tables included in every export
 * The constants {@see self::EXPORT_TABLES} and {@see self::RELATED_TABLES} define
 * which records EXT:impexp fetches. `RELATED_TABLES` are included only when they
 * are referenced by a news record; they are never exported "in bulk".
 */
final class NewsExportService
{
    /**
     * Primary tables to export when a storage PID is given.
     *
     * EXT:impexp fetches ALL records of these tables that reside on the
     * requested PID(s). For `setRecord()` calls (individual UIDs) only
     * `tx_news_domain_model_news` is targeted directly.
     *
     * @var list<string>
     */
    private const EXPORT_TABLES = [
        'tx_news_domain_model_news',
    ];

    /**
     * Tables that are pulled in automatically because of FK / MM relations.
     *
     * These entries are passed to {@see Export::setRelOnlyTables()} so that
     * EXT:impexp includes related records without treating them as root-level
     * export candidates.
     *
     * `sys_file` and `sys_file_metadata` are intentionally excluded:
     * including them triggers a cascade (sys_file ↔ sys_file_metadata circular,
     * and sys_file → sys_file_storage which is site-specific and cannot be
     * transferred), producing duplicate records and new LOST RELATION warnings
     * for the storage. The resulting "LOST RELATION" notices for sys_file are
     * expected EXT:impexp behaviour when files are not embedded. Enable the
     * "Embed FAL files" option ({@see ExportOptions::$includeFiles}) to produce
     * a fully self-contained archive that resolves all file relations.
     *
     * @var list<string>
     */
    private const RELATED_TABLES = [
        'tx_news_domain_model_tag',
        'tx_news_domain_model_link',
        'sys_category',
        'sys_file_reference',
    ];

    public function __construct(
        private readonly NewsFieldMapper $fieldMapper,
        private readonly ConnectionPool  $connectionPool,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check whether the `tx_news_domain_model_news` table exists in the database.
     *
     * Used by the backend module and CLI command to show a meaningful error
     * instead of a raw Doctrine exception when the georgringer/news extension
     * is not installed on the current system.
     */
    public function isNewsTableAvailable(): bool
    {
        try {
            $this->connectionPool
                ->getQueryBuilderForTable('tx_news_domain_model_news')
                ->count('uid')
                ->from('tx_news_domain_model_news')
                ->setMaxResults(1)
                ->executeQuery();

            return true;
        } catch (DBALException) {
            return false;
        }
    }

    /**
     * Export news records and return the result as a raw t3d XML string.
     *
     * The string is ready to be saved as a `.t3d` file or streamed as an HTTP
     * response. If a field mapping is configured in {@see ExportOptions::$fieldMap}
     * the XML is transformed before it is returned.
     *
     * @param ExportOptions $options Validated export parameters.
     * @return string Raw t3d XML content.
     * @throws \RuntimeException When the export pipeline fails to produce output.
     */
    public function exportToString(ExportOptions $options): string
    {
        $export = $this->createExport($options);
        $export->process();

        $xml = $export->render();

        if ($options->fieldMap !== '') {
            $xml = $this->fieldMapper->transformXml($xml, $options->fieldMap);
        }

        return $xml;
    }

    /**
     * Return a list of news record rows for the given PIDs.
     *
     * Used by the backend module controller to render the preview table before
     * the user submits the export form. Deleted records are always excluded.
     * Hidden/disabled records are excluded only when {@see $excludeDisabled} is
     * true, so that editors can see disabled draft content by default.
     *
     * @param list<positive-int>  $pids            Storage PIDs to query.
     * @param bool                $excludeDisabled Also exclude hidden/disabled records (hidden=1).
     * @param int                 $limit           Maximum number of rows to return (0 = unlimited).
     * @param int                 $offset          Number of rows to skip (for pagination).
     * @return list<array<string, mixed>>          Rows from tx_news_domain_model_news.
     */
    public function fetchNewsPreview(array $pids, bool $excludeDisabled = false, int $limit = 0, int $offset = 0): array
    {
        if ($pids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');

        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        if ($excludeDisabled) {
            $queryBuilder->getRestrictions()
                ->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $query = $queryBuilder
            ->select(
                'uid',
                'pid',
                'title',
                'datetime',
                'hidden',
                'categories',
                'tags',
                'fal_media',
                'fal_related_files',
                'related_links',
                'type',
                'path_segment',
            )
            ->from('tx_news_domain_model_news')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter(
                        $pids,
                        \Doctrine\DBAL\ArrayParameterType::INTEGER
                    )
                )
            )
            ->orderBy('datetime', 'DESC');

        if ($limit > 0) {
            $query->setMaxResults($limit);
        }
        if ($offset > 0) {
            $query->setFirstResult($offset);
        }

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $query->executeQuery()->fetchAllAssociative();
        } catch (DBALException) {
            return [];
        }

        return $rows;
    }

    /**
     * Return the total number of news records on the given PIDs.
     *
     * Respects the same restriction as {@see self::fetchNewsPreview()} (deleted
     * records excluded, hidden records counted).
     *
     * @param list<positive-int> $pids Storage PIDs.
     */
    public function countNews(array $pids): int
    {
        if ($pids === []) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        try {
            return (int)$queryBuilder
                ->count('uid')
                ->from('tx_news_domain_model_news')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter(
                            $pids,
                            \Doctrine\DBAL\ArrayParameterType::INTEGER
                        )
                    )
                )
                ->executeQuery()
                ->fetchOne();
        } catch (DBALException) {
            return 0;
        }
    }

    /**
     * Return the available field-mapping configuration names.
     *
     * Scans the `Configuration/FieldMappings/` directory for `*.yaml` files and
     * returns the file stems so that the backend module can populate a selector.
     *
     * @return list<string> Sorted list of mapping names without file extension.
     */
    public function availableFieldMaps(): array
    {
        $directory = GeneralUtility::getFileAbsFileName(
            'EXT:gedankenfolger_news_export/Configuration/FieldMappings/'
        );

        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '*.yaml');
        if ($files === false) {
            return [];
        }

        $names = array_map(
            static fn(string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $files
        );

        sort($names);

        return $names;
    }

    /**
     * Collect the absolute filesystem paths of all local FAL files referenced
     * by the news records that match the given export options.
     *
     * Only files on local-driver storages (driver = 'Local') are returned;
     * remote storages (S3, SFTP, …) are silently skipped because their files
     * are not accessible from the local filesystem.
     *
     * The returned list contains one entry per unique physical file (duplicates
     * from multiple sys_file_reference rows pointing to the same sys_file are
     * deduplicated). Each entry carries:
     *   - `absolute` – the full server-side path, ready for `ZipArchive::addFile()`.
     *   - `zipPath`  – the relative path inside the ZIP archive, preserving the
     *                  FAL storage prefix so that no filename collisions occur.
     *
     * @param ExportOptions $options Validated export parameters.
     * @return list<array{absolute: string, zipPath: string}>
     */
    public function collectReferencedLocalFiles(ExportOptions $options): array
    {
        // ---- 1. Resolve news UIDs for the given PIDs ----------------------------

        $newsUids = $options->uids;

        if ($options->pids !== []) {
            $qb = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
            $qb->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            if ($options->excludeDisabled) {
                $qb->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
            }
            try {
                $rows = $qb->select('uid')
                    ->from('tx_news_domain_model_news')
                    ->where(
                        $qb->expr()->in(
                            'pid',
                            $qb->createNamedParameter($options->pids, ArrayParameterType::INTEGER)
                        )
                    )
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (DBALException) {
                return [];
            }
            foreach ($rows as $row) {
                $newsUids[] = (int)$row['uid'];
            }
        }

        $newsUids = array_values(array_unique($newsUids));
        if ($newsUids === []) {
            return [];
        }

        // ---- 2. Find sys_file UIDs referenced from those news records -----------

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        try {
            $refs = $qb->select('uid_local')
                ->from('sys_file_reference')
                ->where(
                    $qb->expr()->eq(
                        'tablenames',
                        $qb->createNamedParameter('tx_news_domain_model_news')
                    ),
                    $qb->expr()->in(
                        'uid_foreign',
                        $qb->createNamedParameter($newsUids, ArrayParameterType::INTEGER)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (DBALException) {
            return [];
        }

        $fileUids = array_values(array_unique(array_column($refs, 'uid_local')));
        if ($fileUids === []) {
            return [];
        }

        // ---- 3. Fetch sys_file rows (storage + identifier) ----------------------

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        try {
            $fileRows = $qb->select('uid', 'storage', 'identifier')
                ->from('sys_file')
                ->where(
                    $qb->expr()->in(
                        'uid',
                        $qb->createNamedParameter($fileUids, ArrayParameterType::INTEGER)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (DBALException) {
            return [];
        }

        // ---- 4. Group files by storage UID --------------------------------------

        /** @var array<int, list<string>> $byStorage */
        $byStorage = [];
        foreach ($fileRows as $row) {
            $byStorage[(int)$row['storage']][] = (string)$row['identifier'];
        }

        // ---- 5. Resolve storage base paths and build absolute paths -------------

        $result = [];

        foreach ($byStorage as $storageUid => $identifiers) {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
            $qb->getRestrictions()->removeAll();
            try {
                $storageRow = $qb->select('driver', 'configuration')
                    ->from('sys_file_storage')
                    ->where(
                        $qb->expr()->eq(
                            'uid',
                            $qb->createNamedParameter($storageUid, ParameterType::INTEGER)
                        )
                    )
                    ->executeQuery()
                    ->fetchAssociative();
            } catch (DBALException) {
                continue;
            }

            // Only local-driver storages can be packaged into a ZIP.
            if ($storageRow === false || $storageRow['driver'] !== 'Local') {
                continue;
            }

            // Parse FlexForm configuration to extract basePath and pathType.
            $config   = GeneralUtility::xml2array((string)$storageRow['configuration']);
            $basePath = (string)($config['data']['sDEF']['lDEF']['basePath']['vDEF'] ?? '');
            $pathType = (string)($config['data']['sDEF']['lDEF']['pathType']['vDEF'] ?? 'relative');

            if ($basePath === '') {
                continue;
            }

            // Make the base path absolute.
            if ($pathType !== 'absolute') {
                $basePath = rtrim(Environment::getPublicPath(), '/') . '/' . ltrim($basePath, '/');
            }
            $basePath = rtrim($basePath, '/');

            foreach ($identifiers as $identifier) {
                $absolute = $basePath . '/' . ltrim($identifier, '/');
                if (!is_file($absolute)) {
                    continue;
                }
                $result[] = [
                    'absolute' => $absolute,
                    // Prefix with storage UID to avoid filename collisions across
                    // storages and to make the ZIP layout self-documenting.
                    'zipPath'  => 'storage_' . $storageUid . '/' . ltrim($identifier, '/'),
                ];
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Create and configure a fresh {@see Export} instance.
     *
     * A new instance is required for every export run because the Export class
     * accumulates state during `process()` and is not designed for reuse.
     *
     * @param ExportOptions $options Validated export parameters.
     */
    private function createExport(ExportOptions $options): Export
    {
        /** @var Export $export */
        $export = GeneralUtility::makeInstance(Export::class);

        $export->setExportFileType($options->fileType);
        $export->setTitle(
            $options->title !== '' ? $options->title : 'News Export ' . date('Y-m-d H:i:s')
        );
        $export->setIncludeExtFileResources($options->includeFiles);
        $export->setExcludeDisabledRecords($options->excludeDisabled);

        // ---- Record selection ---------------------------------------------------

        // Export all news on the given storage PIDs.
        if ($options->pids !== []) {
            $listSelectors = array_map(
                static fn(int $pid): string => 'tx_news_domain_model_news:' . $pid,
                $options->pids
            );
            $export->setList($listSelectors);
        }

        // Export specific news UIDs (takes precedence alongside PID selection).
        if ($options->uids !== []) {
            $recordSelectors = array_map(
                static fn(int $uid): string => 'tx_news_domain_model_news:' . $uid,
                $options->uids
            );
            $export->setRecord($recordSelectors);
        }

        // ---- Relation configuration --------------------------------------------

        // Pull in related records (tags, links, categories, FAL) only when they
        // are actually referenced from a selected news record, rather than
        // exporting the entire table contents.
        $export->setRelOnlyTables(self::RELATED_TABLES);

        return $export;
    }
}
