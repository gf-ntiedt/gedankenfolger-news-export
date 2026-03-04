<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * Icon registry for EXT:gedankenfolger_news_export.
 *
 * Each key is an icon identifier referenced from Modules.php, Fluid templates,
 * and TCA configurations throughout this extension.
 *
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Icon/Index.html
 */
return [
    'gedankenfolger-news-export-module' => [
        'provider' => SvgIconProvider::class,
        'source'   => 'EXT:gedankenfolger_news_export/Resources/Public/Icons/module.svg',
    ],
];
