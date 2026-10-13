<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Service;

use Doctrine\DBAL\Exception as DBALException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Checks the permissions of the current backend user before news data or files are delivered.
 *
 * Administrators are never restricted. Every other user needs the `tables_select`
 * permission for the news table, read access to the page of every requested news
 * record (page permissions and web mount) and read access to every referenced file
 * (file mount boundaries of its storage).
 *
 * @author  Niels Tiedt <niels.tiedt@gedankenfolger.de>
 * @company Gedankenfolger GmbH
 */
final class ExportAccessGuard
{
    private const NEWS_TABLE = 'tx_news_domain_model_news';

    /**
     * @param ConnectionPool  $connectionPool  Provides query builders for the UID lookup.
     * @param ResourceFactory $resourceFactory Resolves sys_file UIDs to file objects.
     */
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Assert that the current user may read the news records selected by PIDs and UIDs.
     *
     * @param list<int> $pids Storage PIDs.
     * @param list<int> $uids News UIDs.
     * @throws ExportAccessDeniedException When the user may not read one of the pages or the news table.
     */
    public function assertCanReadNews(array $pids, array $uids): void
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return;
        }

        if (!$backendUser->check('tables_select', self::NEWS_TABLE)) {
            throw new ExportAccessDeniedException('No read access to table ' . self::NEWS_TABLE . '.', 1760000001);
        }

        $pagesToCheck = $pids;
        if ($uids !== []) {
            $pagesToCheck = array_merge($pagesToCheck, $this->findPidsOfNews($uids));
        }

        foreach (array_unique($pagesToCheck) as $pid) {
            $this->assertPageReadable($backendUser, $pid);
        }
    }

    /**
     * Assert that the current user may read the given files.
     *
     * Files that are not in the file index are skipped, because they cannot be exported anyway.
     *
     * @param list<int> $fileUids sys_file UIDs.
     * @throws ExportAccessDeniedException When one of the files is outside the file mounts of the user.
     */
    public function assertCanReadFiles(array $fileUids): void
    {
        if ($this->getBackendUser()->isAdmin()) {
            return;
        }

        foreach ($fileUids as $fileUid) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (FileDoesNotExistException) {
                continue;
            }

            if (!$file->getStorage()->checkFileActionPermission('read', $file)) {
                throw new ExportAccessDeniedException('No read access to file ' . $fileUid . '.', 1760000002);
            }
        }
    }

    /**
     * @throws ExportAccessDeniedException When the page is not readable for the user.
     */
    private function assertPageReadable(BackendUserAuthentication $backendUser, int $pid): void
    {
        $page = $pid > 0 ? BackendUtility::getRecord('pages', $pid) : null;

        if (!is_array($page)
            || !$backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW)
            || !$backendUser->isInWebMount($pid)
        ) {
            throw new ExportAccessDeniedException('No read access to page ' . $pid . '.', 1760000003);
        }
    }

    /**
     * Look up the storage PIDs of the given news UIDs.
     *
     * @param list<int> $uids News UIDs.
     * @return list<int>
     * @throws ExportAccessDeniedException When the lookup fails, because the access cannot be verified then.
     */
    private function findPidsOfNews(array $uids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::NEWS_TABLE);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        try {
            $rows = $queryBuilder->select('pid')
                ->from(self::NEWS_TABLE)
                ->where(
                    // The UIDs are integers, so they are safe to pass as literal values.
                    $queryBuilder->expr()->in('uid', $uids)
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (DBALException $e) {
            throw new ExportAccessDeniedException('The page permissions could not be verified.', 1760000004, $e);
        }

        return array_map(static fn(array $row): int => (int)$row['pid'], $rows);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new ExportAccessDeniedException('No backend user available.', 1760000005);
        }

        return $backendUser;
    }
}
