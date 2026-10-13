<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\Service;

/**
 * Thrown when the current backend user may not read the requested pages, news records or files.
 *
 * @author  Niels Tiedt <niels.tiedt@gedankenfolger.de>
 * @company Gedankenfolger GmbH
 */
final class ExportAccessDeniedException extends \RuntimeException {}
