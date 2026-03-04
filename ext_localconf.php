<?php

declare(strict_types=1);

// Prevent direct script access – all TYPO3 entry points set this constant.
defined('TYPO3') or die();

// All registrations (CLI commands, backend modules, icons) are handled via
// dedicated configuration files (Services.yaml, Modules.php, Icons.php) so
// that TYPO3's dependency-injection container can manage them properly.

// Allow .t3d files to pass TYPO3 13's FAL upload security check
// (security.system.enforceAllowedFileExtensions). Without this, the TYPO3
// Import/Export module rejects uploaded .t3d files because the extension is
// not in the default textfile_ext / mediafile_ext / miscfile_ext lists.
(static function (): void {
    $miscExt = $GLOBALS['TYPO3_CONF_VARS']['SYS']['miscfile_ext'] ?? '';
    if ($miscExt !== '' && !in_array('t3d', explode(',', $miscExt), true)) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['miscfile_ext'] .= ',t3d';
    }
})();
