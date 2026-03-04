<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Command;

use Gedankenfolger\GedankenfolgerNewsExport\Service\ExportOptions;
use Gedankenfolger\GedankenfolgerNewsExport\Service\NewsExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * CLI command that exports georgringer/news records as a .t3d file.
 *
 * ## Usage
 *
 * ```bash
 * # Export all news from storage PID 42 (XML format, no field mapping):
 * vendor/bin/typo3 news:export --pid=42
 *
 * # Export with cross-version field mapping and compressed output:
 * vendor/bin/typo3 news:export --pid=42 --type=t3d_compressed --field-map=news-v10-to-v13
 *
 * # Export specific news records only:
 * vendor/bin/typo3 news:export --pid=42 --uid=101,102,103
 *
 * # Custom output path:
 * vendor/bin/typo3 news:export --pid=42 --output=/var/backups/news-export.t3d
 *
 * # Include embedded FAL file binaries:
 * vendor/bin/typo3 news:export --pid=42 --include-files
 *
 * # Exclude hidden/disabled records:
 * vendor/bin/typo3 news:export --pid=42 --exclude-disabled
 * ```
 *
 * ## Exit codes
 * - `0` SUCCESS – export written to output file.
 * - `1` ERROR   – see the error message printed to STDERR.
 *
 * ## Scheduler support
 * The command is tagged with `schedulable: true` in Services.yaml and can
 * therefore be configured as a recurring TYPO3 Scheduler task.
 */
#[AsCommand(
    name: 'news:export',
    description: 'Export georgringer/news records as a .t3d file',
)]
final class ExportNewsCommand extends Command
{
    public function __construct(
        private readonly NewsExportService $exportService,
    ) {
        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Command definition
    // -------------------------------------------------------------------------

    /**
     * Define all accepted input options.
     *
     * All options are optional so that the command can be invoked non-interactively
     * from cron jobs or Scheduler tasks without requiring interactive prompts.
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                'pid',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated storage PID(s) to export all news from (e.g. "42" or "42,43,44").',
            )
            ->addOption(
                'uid',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated news UID(s) to export instead of (or in addition to) a PID filter.',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Absolute or relative output file path. Defaults to "news-export-<timestamp>.t3d" in the current working directory.',
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'Export file type: "xml" (default), "t3d", or "t3d_compressed".',
                'xml',
            )
            ->addOption(
                'field-map',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the YAML field-mapping configuration to apply (file stem without .yaml extension). '
                . 'Example: "news-v10-to-v13".',
                '',
            )
            ->addOption(
                'title',
                null,
                InputOption::VALUE_REQUIRED,
                'Human-readable title stored in the .t3d metadata header.',
                '',
            )
            ->addOption(
                'include-files',
                null,
                InputOption::VALUE_NONE,
                'Embed FAL file binaries inside the .t3d archive (increases file size significantly).',
            )
            ->addOption(
                'exclude-disabled',
                null,
                InputOption::VALUE_NONE,
                'Exclude hidden/disabled records (hidden=1) from the export. Soft-deleted records (deleted=1) are always excluded.',
            );
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Execute the export command.
     *
     * 1. Parse and validate all options via {@see ExportOptions::fromArray()}.
     * 2. Delegate to {@see NewsExportService::exportToString()}.
     * 3. Write the result to the requested output path.
     *
     * @param InputInterface  $input  Symfony Console input.
     * @param OutputInterface $output Symfony Console output.
     * @return int Exit code: {@see Command::SUCCESS} or {@see Command::FAILURE}.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // TYPO3 backend bootstrap is required because EXT:impexp accesses
        // backend user context (workspaces, language overlays, etc.).
        Bootstrap::initializeBackendAuthentication();

        // ---- Parse options ---------------------------------------------------
        $options = ExportOptions::fromArray([
            'pids'         => $this->parseCommaSeparated((string)($input->getOption('pid') ?? '')),
            'uids'         => $this->parseCommaSeparated((string)($input->getOption('uid') ?? '')),
            'fileType'     => (string)($input->getOption('type') ?? 'xml'),
            'fieldMap'     => (string)($input->getOption('field-map') ?? ''),
            'title'        => (string)($input->getOption('title') ?? ''),
            'includeFiles'    => (bool)$input->getOption('include-files'),
            'excludeDisabled' => (bool)$input->getOption('exclude-disabled'),
        ]);

        if ($options->isEmpty()) {
            $io->error('Please provide at least one --pid or --uid option.');
            return Command::FAILURE;
        }

        // ---- Run export -------------------------------------------------------
        $io->section('Running news export…');

        if ($io->isVerbose()) {
            $io->writeln(sprintf('  PIDs       : %s', implode(', ', $options->pids) ?: '—'));
            $io->writeln(sprintf('  UIDs       : %s', implode(', ', $options->uids) ?: '—'));
            $io->writeln(sprintf('  File type  : %s', $options->fileType));
            $io->writeln(sprintf('  Field map  : %s', $options->fieldMap ?: '—'));
            $io->writeln(sprintf('  Incl. files    : %s', $options->includeFiles ? 'yes' : 'no'));
            $io->writeln(sprintf('  Excl. disabled : %s', $options->excludeDisabled ? 'yes' : 'no'));
        }

        try {
            $xml = $this->exportService->exportToString($options);
        } catch (\Throwable $e) {
            $io->error(sprintf('Export failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // ---- Write output file -----------------------------------------------
        $outputPath = $this->resolveOutputPath(
            (string)($input->getOption('output') ?? ''),
            $options->fileType
        );

        if (file_put_contents($outputPath, $xml) === false) {
            $io->error(sprintf('Could not write output file: %s', $outputPath));
            return Command::FAILURE;
        }

        $sizeKb = round(strlen($xml) / 1024, 1);
        $io->success(sprintf(
            'Export written to: %s  (%s KB, %d characters)',
            $outputPath,
            number_format($sizeKb, 1),
            strlen($xml)
        ));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Split a comma-separated string into a trimmed array of non-empty values.
     *
     * @param string $raw Comma-separated input (e.g. "42, 43, 44").
     * @return list<string>
     */
    private function parseCommaSeparated(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $raw))
            )
        );
    }

    /**
     * Resolve the absolute output file path.
     *
     * When the user provides a path it is used as-is (relative paths are resolved
     * against the current working directory). When no path is given a timestamped
     * default file name is created in the current working directory.
     *
     * @param string $raw      User-supplied output path (may be empty).
     * @param string $fileType Export file type used to derive the file extension.
     * @return string Absolute output file path.
     */
    private function resolveOutputPath(string $raw, string $fileType): string
    {
        if ($raw !== '') {
            // Make relative paths absolute against the current working directory.
            return str_starts_with($raw, '/') ? $raw : (getcwd() . '/' . $raw);
        }

        $extension = match ($fileType) {
            'xml'            => 'xml',
            't3d_compressed' => 't3d_compressed',
            default          => 't3d',
        };

        return getcwd() . '/news-export-' . date('Ymd-His') . '.' . $extension;
    }
}
