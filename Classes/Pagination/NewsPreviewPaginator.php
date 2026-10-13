<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Pagination;

use TYPO3\CMS\Core\Pagination\AbstractPaginator;

/**
 * Paginator that loads only the rows of the current page from the database.
 *
 * {@see \TYPO3\CMS\Core\Pagination\ArrayPaginator} needs the complete result
 * set in memory and slices it in PHP. This paginator is given the total number
 * of records and a loader that fetches a single page (limit and offset), so
 * the preview table never holds more than one page of rows.
 *
 * @author  Niels Tiedt <niels.tiedt@gedankenfolger.de>
 * @company Gedankenfolger GmbH
 */
final class NewsPreviewPaginator extends AbstractPaginator
{
    /** @var list<array<string, mixed>> */
    private array $paginatedItems = [];

    /**
     * @param int                                            $totalItems        Total number of records on all pages.
     * @param \Closure(int, int): list<array<string, mixed>> $pageLoader        Loads the rows of one page; receives the limit and the offset.
     * @param int                                            $currentPageNumber Current page (1-based).
     * @param int                                            $itemsPerPage      Number of rows per page.
     * @throws \InvalidArgumentException When the page number or the number of rows per page is lower than 1.
     */
    public function __construct(
        private readonly int $totalItems,
        private readonly \Closure $pageLoader,
        int $currentPageNumber = 1,
        int $itemsPerPage = 10,
    ) {
        $this->setCurrentPageNumber($currentPageNumber);
        $this->setItemsPerPage($itemsPerPage);

        $this->updateInternalState();
    }

    /**
     * Return the rows of the current page.
     *
     * @return list<array<string, mixed>> Rows loaded by the page loader.
     */
    public function getPaginatedItems(): iterable
    {
        return $this->paginatedItems;
    }

    protected function updatePaginatedItems(int $itemsPerPage, int $offset): void
    {
        $this->paginatedItems = ($this->pageLoader)($itemsPerPage, $offset);
    }

    protected function getTotalAmountOfItems(): int
    {
        return $this->totalItems;
    }

    protected function getAmountOfItemsOnCurrentPage(): int
    {
        return count($this->paginatedItems);
    }
}
