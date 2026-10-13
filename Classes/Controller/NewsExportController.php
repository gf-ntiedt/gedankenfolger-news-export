<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Controller;

use Gedankenfolger\GedankenfolgerNewsExport\Pagination\NewsPreviewPaginator;
use Gedankenfolger\GedankenfolgerNewsExport\Service\ExportAccessDeniedException;
use Gedankenfolger\GedankenfolgerNewsExport\Service\ExportAccessGuard;
use Gedankenfolger\GedankenfolgerNewsExport\Service\ExportOptions;
use Gedankenfolger\GedankenfolgerNewsExport\Service\NewsExportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

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
 *
 * ## Registration
 * The controller is tagged as `backend.controller` in Services.yaml (compatible
 * with TYPO3 12 and 13). The #[AsController] attribute is intentionally omitted
 * so that the class loads without errors on TYPO3 12, where that attribute class
 * does not exist.
 *
 * @author  Niels Tiedt <niels.tiedt@gedankenfolger.de>
 * @company Gedankenfolger GmbH
 */
final class NewsExportController extends ActionController
{
    /** Number of news rows displayed per page in the preview table. */
    private const ITEMS_PER_PAGE = 20;

    /**
     * @param ModuleTemplateFactory $moduleTemplateFactory Creates the backend module template.
     * @param NewsExportService     $exportService         Service that produces the export and the preview data.
     * @param LoggerInterface       $logger                Receives the details of failed exports.
     * @param ExportAccessGuard     $accessGuard           Checks the permissions of the backend user for the preview.
     */
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly NewsExportService $exportService,
        private readonly LoggerInterface $logger,
        private readonly ExportAccessGuard $accessGuard,
    ) {}

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Display the export configuration form and a paginated news preview table.
     *
     * @param int $currentPage Current pagination page (1-based).
     * @return ResponseInterface Rendered backend module response.
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

        // Show no preview rows for pages the backend user may not read.
        try {
            $this->accessGuard->assertCanReadNews($pids, []);
        } catch (ExportAccessDeniedException $e) {
            $this->logger->warning('News export preview denied: ' . $e->getMessage());
            $this->addFlash('accessDenied', ContextualFeedbackSeverity::ERROR);
            $pids = [];
        }

        // Load records for the preview table (paginated).
        $excludeDisabled = (bool)($formData['excludeDisabled'] ?? false);
        $totalCount  = $this->exportService->countNews($pids, $excludeDisabled);
        $paginator   = new NewsPreviewPaginator(
            $totalCount,
            fn(int $limit, int $offset): array => $this->exportService->fetchNewsPreview($pids, $excludeDisabled, $limit, $offset),
            max(1, $currentPage),
            self::ITEMS_PER_PAGE,
        );
        $pagination  = new SimplePagination($paginator);

        // Generate fresh CSRF tokens – one per form action.
        /** @var BackendFormProtection $formProtection */
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
            'totalCount'      => $totalCount,
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
     *
     * @return ResponseInterface The .t3d download, or a redirect to `indexAction` on error.
     */
    public function exportAction(): ResponseInterface
    {
        // Guard: table must exist before any export attempt.
        if (!$this->exportService->isNewsTableAvailable()) {
            $this->addFlash('extensionNotInstalled', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        $body = (array)($this->request->getParsedBody() ?? []);

        // ---- CSRF validation -------------------------------------------------
        /** @var BackendFormProtection $formProtection */
        $formProtection = GeneralUtility::makeInstance(FormProtectionFactory::class)
            ->createFromRequest($this->request);

        $token = (string)($body['csrfToken'] ?? '');
        if (!$formProtection->validateToken($token, 'news_export', 'export')) {
            $this->addFlash('csrfError', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        // ---- Build and validate options --------------------------------------
        $options = ExportOptions::fromArray($body);

        if ($options->isEmpty()) {
            $this->addFlash('noRecords', ContextualFeedbackSeverity::WARNING);
            return $this->redirect('index');
        }

        // ---- Run export -------------------------------------------------------
        try {
            $xml = $this->exportService->exportToString($options);
        } catch (ExportAccessDeniedException $e) {
            $this->logger->warning('News export denied: ' . $e->getMessage());
            $this->addFlash('accessDenied', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        } catch (\Throwable $e) {
            $this->logger->error('News export failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->addFlash('exportError', ContextualFeedbackSeverity::ERROR);
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
     *
     * @return ResponseInterface The ZIP download, or a redirect to `indexAction` on error.
     */
    public function downloadFilesAction(): ResponseInterface
    {
        // Guard: table must exist before any export attempt.
        if (!$this->exportService->isNewsTableAvailable()) {
            $this->addFlash('extensionNotInstalled', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        $body = (array)($this->request->getParsedBody() ?? []);

        // ---- CSRF validation -------------------------------------------------
        /** @var BackendFormProtection $formProtection */
        $formProtection = GeneralUtility::makeInstance(FormProtectionFactory::class)
            ->createFromRequest($this->request);

        $token = (string)($body['csrfTokenFiles'] ?? '');
        if (!$formProtection->validateToken($token, 'news_export', 'downloadFiles')) {
            $this->addFlash('csrfError', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        // ---- Build and validate options --------------------------------------
        $options = ExportOptions::fromArray($body);

        if ($options->isEmpty()) {
            $this->addFlash('noRecords', ContextualFeedbackSeverity::WARNING);
            return $this->redirect('index');
        }

        // ---- Collect referenced local files ----------------------------------
        try {
            $files = $this->exportService->collectReferencedLocalFiles($options);
        } catch (ExportAccessDeniedException $e) {
            $this->logger->warning('News file download denied: ' . $e->getMessage());
            $this->addFlash('accessDenied', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        if ($files === []) {
            $this->addFlash('noFiles', ContextualFeedbackSeverity::INFO);
            return $this->redirect('index');
        }

        // ---- Build ZIP in a temp file ----------------------------------------
        $tmpFile = tempnam(sys_get_temp_dir(), 'news_files_');

        if ($tmpFile === false) {
            $this->addFlash('zipTempFile', ContextualFeedbackSeverity::ERROR, [], 'zipError');
            return $this->redirect('index');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            $this->addFlash('zipCreate', ContextualFeedbackSeverity::ERROR, [], 'zipError');
            return $this->redirect('index');
        }

        foreach ($files as $entry) {
            if (!$zip->addFile($entry['absolute'], $entry['zipPath'])) {
                $zip->close();
                @unlink($tmpFile);
                $this->addFlash('zipAddFile', ContextualFeedbackSeverity::ERROR, [$entry['zipPath']], 'zipError');
                return $this->redirect('index');
            }
        }

        if (!$zip->close()) {
            @unlink($tmpFile);
            $this->addFlash('zipWrite', ContextualFeedbackSeverity::ERROR, [], 'zipError');
            return $this->redirect('index');
        }

        $zipContent = file_get_contents($tmpFile);
        @unlink($tmpFile);

        if ($zipContent === false) {
            $this->addFlash('zipRead', ContextualFeedbackSeverity::ERROR, [], 'zipError');
            return $this->redirect('index');
        }

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
     * Add a flash message whose title and text come from the language file.
     *
     * Looks up `flash.<key>.message` and `flash.<titleKey>.title` in `locallang.xlf`.
     *
     * @param string                     $key       Label key without the `flash.` prefix and the `.message` suffix.
     * @param ContextualFeedbackSeverity $severity  Severity of the message.
     * @param list<string>               $arguments Values for the placeholders of the message text.
     * @param string|null                $titleKey  Label key of the title when it differs from `$key`.
     */
    private function addFlash(string $key, ContextualFeedbackSeverity $severity, array $arguments = [], ?string $titleKey = null): void
    {
        $prefix = 'LLL:EXT:gedankenfolger_news_export/Resources/Private/Language/locallang.xlf:flash.';

        $this->addFlashMessage(
            LocalizationUtility::translate($prefix . $key . '.message', null, $arguments) ?? $key,
            LocalizationUtility::translate($prefix . ($titleKey ?? $key) . '.title') ?? '',
            $severity
        );
    }

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
        // Use the correct extension so TYPO3's import module picks the right
        // parser: xml → XML parser, t3d → binary parser. Mixing them causes
        // "Undefined array key 3" in Import::getNextFilePart().
        $extension = match ($options->fileType) {
            'xml'            => 'xml',
            't3d_compressed' => 't3d_compressed',
            default          => 't3d',
        };

        return sprintf('news-export-%s.%s', date('Ymd-His'), $extension);
    }
}
