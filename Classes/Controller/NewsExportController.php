<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Controller;

use Gedankenfolger\GedankenfolgerNewsExport\Service\ExportOptions;
use Gedankenfolger\GedankenfolgerNewsExport\Service\NewsExportService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use ZipArchive;

/**
 * Backend module controller for EXT:gedankenfolger_news_export.
 *
 * ## Actions
 *
 * ### indexAction
 * Renders the export form together with a paginated preview table of the news
 * records that live on the currently selected storage page. All input is read
 * from the TYPO3 page tree (`id` query parameter) and the previous form POST
 * (preserved in session-like GET round-trip parameters).
 *
 * ### exportAction
 * Validates the CSRF token, builds an {@see ExportOptions} DTO, delegates to
 * {@see NewsExportService::exportToString()}, and streams the result as a file
 * download. It never writes files to the server's filesystem.
 *
 * ## Security
 * - CSRF protection via TYPO3's {@see FormProtectionFactory} (token generated
 *   in `indexAction`, validated in `exportAction`).
 * - All user-supplied integers are cast via `ExportOptions::fromArray()`.
 * - No dynamic shell execution; export is pure PHP.
 * - Access restricted to logged-in backend users (see Modules.php `access` key).
 */
#[AsController]
final class NewsExportController extends ActionController
{
    /** Number of news rows displayed per page in the preview table. */
    private const ITEMS_PER_PAGE = 20;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly NewsExportService     $exportService,
    ) {}

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the export configuration form and a paginated news preview table.
     *
     * @param int $currentPage Current pagination page (1-based).
     */
    public function indexAction(int $currentPage = 1): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Guard: EXT:news (georgringer/news) must be installed so that the
        // tx_news_domain_model_news table exists. Show a clear notice instead
        // of letting Doctrine throw a TableNotFoundException.
        if (!$this->exportService->isNewsTableAvailable()) {
            $moduleTemplate->assign('newsNotInstalled', true);
            return $moduleTemplate->renderResponse('NewsExport/Index');
        }

        // TYPO3 backend passes the currently selected page-tree page as `id`.
        $pageId = (int)($this->request->getQueryParams()['id'] ?? 0);

        // Determine which PIDs to preview; fall back to the active page when
        // no explicit PID is set via the form.
        $formData = (array)($this->request->getQueryParams()['tx_gedankenfolger_news_export_gedankenfolger_news_export'] ?? []);
        $pids     = $this->resolvePids($formData, $pageId);

        // Load records for the preview table (paginated).
        $excludeDisabled = (bool)($formData['excludeDisabled'] ?? false);
        $allRecords  = $this->exportService->fetchNewsPreview($pids, $excludeDisabled);
        $paginator   = new ArrayPaginator($allRecords, max(1, $currentPage), self::ITEMS_PER_PAGE);
        $pagination  = new SimplePagination($paginator);

        // Generate fresh CSRF tokens – one per form action.
        /** @var \TYPO3\CMS\Core\FormProtection\BackendFormProtection $formProtection */
        $formProtection = GeneralUtility::makeInstance(FormProtectionFactory::class)
            ->createFromRequest($this->request);
        $csrfToken      = $formProtection->generateToken('news_export', 'export');
        $csrfTokenFiles = $formProtection->generateToken('news_export', 'downloadFiles');

        $moduleTemplate->assignMultiple([
            'pageId'          => $pageId,
            'pids'            => implode(',', $pids),
            'paginator'       => $paginator,
            'pagination'      => $pagination,
            'currentPage'     => $currentPage,
            'totalCount'      => count($allRecords),
            'fieldMaps'       => $this->exportService->availableFieldMaps(),
            'csrfToken'       => $csrfToken,
            'csrfTokenFiles'  => $csrfTokenFiles,
            // Preserve previously submitted form values for display.
            'formData'        => $formData,
        ]);

        return $moduleTemplate->renderResponse('NewsExport/Index');
    }

    /**
     * Process the export form POST and stream the .t3d file as a download.
     *
     * On any validation error the user is redirected back to `indexAction` with
     * an error flash message so that the form can be corrected.
     */
    public function exportAction(): ResponseInterface
    {
        // Guard: table must exist before any export attempt.
        if (!$this->exportService->isNewsTableAvailable()) {
            $this->addFlashMessage(
                'The georgringer/news extension is not installed. Please install it before exporting.',
                'Extension not installed',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $body = (array)($this->request->getParsedBody() ?? []);

        // ---- CSRF validation -------------------------------------------------
        /** @var \TYPO3\CMS\Core\FormProtection\BackendFormProtection $formProtection */
        $formProtection = GeneralUtility::makeInstance(FormProtectionFactory::class)
            ->createFromRequest($this->request);

        $token = (string)($body['csrfToken'] ?? '');
        if (!$formProtection->validateToken($token, 'news_export', 'export')) {
            $this->addFlashMessage(
                'The form protection token was invalid. Please reload the page and try again.',
                'Security error',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        // ---- Build and validate options --------------------------------------
        $options = ExportOptions::fromArray($body);

        if ($options->isEmpty()) {
            $this->addFlashMessage(
                'Please provide at least one storage PID or a specific news UID before exporting.',
                'No records selected',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::WARNING
            );
            return $this->redirect('index');
        }

        // ---- Run export -------------------------------------------------------
        try {
            $xml = $this->exportService->exportToString($options);
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                sprintf('Export failed: %s', $e->getMessage()),
                'Export error',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        // ---- Stream response --------------------------------------------------
        $fileName    = $this->buildFileName($options);
        $contentType = 'application/octet-stream';

        return $this->responseFactory
            ->createResponse()
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Content-Length', (string)strlen($xml))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($this->streamFactory->createStream($xml));
    }

    /**
     * Build a ZIP archive of all local FAL files referenced by the selected
     * news records and stream it as a download.
     *
     * Only files on local-driver storages are included. Files that do not
     * exist on disk are silently skipped. When no local files are found the
     * user is redirected back to indexAction with an info flash message.
     */
    public function downloadFilesAction(): ResponseInterface
    {
        // Guard: table must exist before any export attempt.
        if (!$this->exportService->isNewsTableAvailable()) {
            $this->addFlashMessage(
                'The georgringer/news extension is not installed. Please install it before exporting.',
                'Extension not installed',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $body = (array)($this->request->getParsedBody() ?? []);

        // ---- CSRF validation -------------------------------------------------
        /** @var \TYPO3\CMS\Core\FormProtection\BackendFormProtection $formProtection */
        $formProtection = GeneralUtility::makeInstance(FormProtectionFactory::class)
            ->createFromRequest($this->request);

        $token = (string)($body['csrfTokenFiles'] ?? '');
        if (!$formProtection->validateToken($token, 'news_export', 'downloadFiles')) {
            $this->addFlashMessage(
                'The form protection token was invalid. Please reload the page and try again.',
                'Security error',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        // ---- Build and validate options --------------------------------------
        $options = ExportOptions::fromArray($body);

        if ($options->isEmpty()) {
            $this->addFlashMessage(
                'Please provide at least one storage PID or a specific news UID before exporting.',
                'No records selected',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::WARNING
            );
            return $this->redirect('index');
        }

        // ---- Collect referenced local files ----------------------------------
        $files = $this->exportService->collectReferencedLocalFiles($options);

        if ($files === []) {
            $this->addFlashMessage(
                'No local FAL files were found for the selected news records. Remote-storage files are not included.',
                'No files found',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::INFO
            );
            return $this->redirect('index');
        }

        // ---- Build ZIP in a temp file ----------------------------------------
        $tmpFile = tempnam(sys_get_temp_dir(), 'news_files_');

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            $this->addFlashMessage(
                'Could not create ZIP archive.',
                'ZIP error',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        foreach ($files as $entry) {
            $zip->addFile($entry['absolute'], $entry['zipPath']);
        }
        $zip->close();

        $zipContent = (string)file_get_contents($tmpFile);
        @unlink($tmpFile);

        // ---- Stream response -------------------------------------------------
        $fileName = sprintf('news-files-%s.zip', date('Ymd-His'));

        return $this->responseFactory
            ->createResponse()
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Content-Length', (string)strlen($zipContent))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($this->streamFactory->createStream($zipContent));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Determine the list of PIDs to use for the preview query.
     *
     * Priority order:
     * 1. Explicit `pids` field from the submitted form data.
     * 2. Current page-tree page ID (`$pageId`).
     * 3. Empty list (nothing shown).
     *
     * @param array<string, mixed> $formData Submitted form parameters.
     * @param int                  $pageId   Current page-tree page ID.
     * @return list<positive-int>
     */
    private function resolvePids(array $formData, int $pageId): array
    {
        if (isset($formData['pids']) && $formData['pids'] !== '') {
            // Accept comma-separated integers from the form input.
            $raw = array_map('trim', explode(',', (string)$formData['pids']));
            return ExportOptions::fromArray(['pids' => $raw])->pids;
        }

        if ($pageId > 0) {
            return [$pageId];
        }

        return [];
    }

    /**
     * Generate a safe download filename for the export archive.
     *
     * @param ExportOptions $options Validated export parameters.
     */
    private function buildFileName(ExportOptions $options): string
    {
        $extension = match ($options->fileType) {
            't3d_compressed' => 't3d_compressed',
            't3d'            => 't3d',
            default          => 't3d',  // xml variant still uses .t3d extension
        };

        return sprintf('news-export-%s.%s', date('Ymd-His'), $extension);
    }
}
