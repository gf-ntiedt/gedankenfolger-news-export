# Changelog

All notable changes to this project will be documented in this file.

## [13.2.0] - 2026-10-13

### Changed

- **preview:** Load only the rows of the current page in the backend module (fca3424)

- **template:** Remove the ineffective f:format.raw() from the file type select value (4c5dca2)


### Documentation

- **phpdoc:** Add missing @author and @company tags to class docblocks (632a1a2)

- **phpdoc:** Add missing @return tags and constructor docblocks (792f79e)

- **phpdoc:** Correct @throws of exportToString to the exception actually thrown (9e53faf)

- **phpdoc:** Correct the uids description, UIDs add to the PID selection instead of overriding it (32a8212)

- **readme:** Document that --field-map aborts for an unknown mapping name (96ffd6c)


### Fixed

- **emconf:** Build the extension description as a single string for TER (bb0dfcb)

- **download:** Check ZIP creation results and remove the temp file on every error path (d49e323)

- **controller:** Localize flash messages and keep exception details out of the backend UI (9732728)

- **command:** Register news:export only via the console.command tag so it appears in typo3 list (37dafc4)

- **command:** Validate the --output path before the export and warn when overwriting (892e5e4)

- **a11y:** Add scope=col to preview table headers and localize the pagination aria-label (671efb0)

- **command:** Abort when the --field-map name does not exist and use a neutral example name (ababc6f)

- **security:** Check page, table and file permissions of the backend user before preview, export and ZIP download (ccb76e7)


### Miscellaneous

- Add .gitattributes to keep dev files out of release archives (b91fbdd)

- **git:** Ignore the local TODO.md and exclude it from release archives (f647755)


## [13.1.4] - 2026-10-13

### Fixed

- **license:** Replace GPL v3 text with GPL v2 to match the declared GPL-2.0-or-later license (2cad6ed)


## [13.1.3] - 2026-10-06

### Fixed

- **static-analysis:** Resolve PHPStan findings and PHP-CS-Fixer style violations (54be347)


## [13.1.2] - 2026-04-08

### Miscellaneous

- Update to version 13.1.2 (1dac697)


## [13.1.1] - 2026-03-04

### Documentation

- **README:** Update TYPO3 version compatibility notes (9d5cc98)


### Miscellaneous

- Update to version 13.1.1 (05edc01)


## [13.1.0] - 2026-03-04

### Added

- **export:** Ensure compatibility with TYPO3 11,12,13 (c285825)


## [13.0.0] - 2026-03-04


