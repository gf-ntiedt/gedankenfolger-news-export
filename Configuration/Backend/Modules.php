<?php

declare(strict_types=1);

use Gedankenfolger\GedankenfolgerNewsExport\Controller\NewsExportController;

/**
 * Backend module configuration for EXT:gedankenfolger_news_export.
 *
 * Registers the "News Export" sub-module under the "Web" main module so that
 * editors can select a storage folder in the page tree, preview the matching
 * news records, and download a .t3d export file directly from the backend.
 *
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ExtensionArchitecture/HowTo/BackendModule/index.html
 */
return [
    'gedankenfolger_news_export' => [
        // Attach this module to the "Web" group in the backend navigation.
        'parent'        => 'web',

        // Place it after the "Info" sub-module.
        'position'      => ['after' => 'web_info'],

        // Require at least "user" access; restrict to non-workspace contexts
        // because exporting workspace versions would produce ambiguous data.
        'access'        => 'user',
        'workspaces'    => 'live',

        'path'          => '/module/gedankenfolger/news-export',

        // All visible labels are resolved from the central locallang file.
        'labels'        => 'LLL:EXT:gedankenfolger_news_export/Resources/Private/Language/locallang.xlf',

        'extensionName' => 'GedankenfolgerNewsExport',

        // Icon registered in Configuration/Icons.php.
        'iconIdentifier' => 'gedankenfolger-news-export-module',

        // Map Extbase controller actions that this module may route to.
        'controllerActions' => [
            NewsExportController::class => [
                // index         – display export form + news preview table
                // export        – process POST, stream .t3d download
                // downloadFiles – process POST, stream ZIP of referenced FAL files
                'index',
                'export',
                'downloadFiles',
            ],
        ],
    ],
];
