<?php

/** @var string $_EXTKEY */
$EM_CONF[$_EXTKEY] = [
    'title'          => 'Gedankenfolger News Export',
    'description'    => 'Exports georgringer/news records as TYPO3 .t3d files. '
        . 'Supports cross-version field mapping (news v10 → v13) and provides '
        . 'both a backend module GUI and a Symfony CLI command.',
    'category'       => 'module',
    'author'         => 'Gedankenfolger',
    'author_email'   => '',
    'author_company' => 'Gedankenfolger',
    'state'          => 'alpha',
    'version'        => '13.1.0',
    'constraints'    => [
        'depends'   => [
            'typo3'  => '11.5.0-13.99.99',
            'impexp' => '11.5.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests'  => [
            // The georgringer/news extension must be installed on the source
            // system; it is listed here as a suggestion so that the Extension
            // Manager can display the dependency, but we do not hard-require
            // a specific minor version to stay flexible.
            'news' => '',
        ],
    ],
];
