<?php

declare(strict_types=1);

namespace Gedankenfolger\GedankenfolgerNewsExport\FieldMapper;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Transforms t3d XML content using YAML-defined field mapping rules.
 *
 * ## Purpose
 * EXT:impexp serialises database rows verbatim into XML. When migrating news
 * records between different versions of the georgringer/news extension the
 * target system may expect different field names or values. This service reads
 * a YAML configuration file and applies the declared transformations to the
 * raw t3d XML string before it is sent to the client or written to disk.
 *
 * ## t3d XML structure (relevant excerpt)
 * ```xml
 * <T3RecordDocument>
 *   <records type="array">
 *     <tx_news_domain_model_news type="array">
 *       <n42 type="array">              <!-- n + uid -->
 *         <data type="array">
 *           <title>My News</title>
 *           <teaser>...</teaser>
 *         </data>
 *         <rels type="array">...</rels>
 *       </n42>
 *     </tx_news_domain_model_news>
 *   </records>
 * </T3RecordDocument>
 * ```
 *
 * ## Supported transforms
 * | Key            | Effect                                                    |
 * |----------------|-----------------------------------------------------------|
 * `rename`         | Rename the XML element (= field name change)              |
 * `set_value`      | Replace the element's text content with a fixed string    |
 * `prefix_value`   | Prepend a string to the existing text content             |
 * `regex_replace`  | Apply a PCRE regex substitution on the text content       |
 *
 * ## YAML config location
 * `EXT:gedankenfolger_news_export/Configuration/FieldMappings/<name>.yaml`
 *
 * @see \Gedankenfolger\GedankenfolgerNewsExport\Service\ExportOptions::$fieldMap
 */
final class NewsFieldMapper
{
    /**
     * In-memory cache of parsed YAML configurations, keyed by mapping name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $configCache = [];

    /**
     * Apply a named field mapping to the given t3d XML string.
     *
     * The original string is returned unchanged when:
     * - the mapping name is empty or contains illegal characters,
     * - the YAML file does not exist or cannot be parsed,
     * - the XML cannot be loaded by libxml.
     *
     * @param string $xmlContent  Raw t3d XML produced by {@see \TYPO3\CMS\Impexp\Export::render()}.
     * @param string $mappingName Mapping config name (alphanumeric / hyphens / underscores only).
     * @return string Transformed t3d XML string.
     */
    public function transformXml(string $xmlContent, string $mappingName): string
    {
        if ($mappingName === '') {
            return $xmlContent;
        }

        $config = $this->loadConfig($mappingName);
        if ($config === [] || !isset($config['tables']) || !is_array($config['tables'])) {
            return $xmlContent;
        }

        $dom = $this->parseXml($xmlContent);
        if ($dom === null) {
            return $xmlContent;
        }

        $xpath = new DOMXPath($dom);

        foreach ($config['tables'] as $tableName => $tableConfig) {
            if (!is_array($tableConfig) || !isset($tableConfig['fields']) || !is_array($tableConfig['fields'])) {
                continue;
            }
            // Only process tables with at least one rule.
            if ($tableConfig['fields'] === []) {
                continue;
            }
            $this->applyTableRules($xpath, $dom, (string)$tableName, $tableConfig['fields']);
        }

        $result = $dom->saveXML();

        return $result !== false ? $result : $xmlContent;
    }

    // -------------------------------------------------------------------------
    // Internal processing
    // -------------------------------------------------------------------------

    /**
     * Apply all field rules for one table to every record node in the document.
     *
     * The XPath pattern targets the `data` child of every record element:
     * `//records/{tableName}/{recordNode}/data/{fieldName}`
     *
     * @param DOMXPath                             $xpath      Evaluator bound to the document.
     * @param DOMDocument                          $dom        Owner document (needed for element renaming).
     * @param string                               $tableName  Database table name.
     * @param array<string, array<string, mixed>>  $fieldRules Rules keyed by source field name.
     */
    private function applyTableRules(DOMXPath $xpath, DOMDocument $dom, string $tableName, array $fieldRules): void
    {
        foreach ($fieldRules as $fieldName => $rule) {
            if (!is_array($rule) || !isset($rule['transform'])) {
                continue;
            }

            // Locate every occurrence of this field across all records of the table.
            // Using a generated XPath string is safe here because both $tableName and
            // $fieldName have been loaded from a trusted YAML file on the filesystem.
            $nodes = $xpath->query(
                sprintf('//records/%s/*/data/%s', $tableName, $fieldName)
            );

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            // Collect into an array first to avoid live-NodeList issues when renaming.
            /** @var list<DOMElement> $elements */
            $elements = [];
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $elements[] = $node;
                }
            }

            foreach ($elements as $element) {
                $this->applyRule($dom, $element, $rule);
            }
        }
    }

    /**
     * Apply a single transformation rule to one DOM element.
     *
     * ### Transform types
     *
     * **rename** – Replace the element with a new element of the given name,
     *   preserving all attributes and child nodes. Required key: `to` (string).
     *
     * **set_value** – Overwrite the element's text content with a fixed value.
     *   Required key: `value` (string).
     *
     * **prefix_value** – Prepend a string to the current text content.
     *   Required key: `prefix` (string).
     *
     * **regex_replace** – Run a PCRE substitution on the text content.
     *   Required keys: `pattern` (string), `replacement` (string).
     *
     * @param DOMDocument                $dom     Owner document.
     * @param DOMElement                 $element Target element to transform.
     * @param array<string, string|int>  $rule    Rule from the YAML configuration.
     */
    private function applyRule(DOMDocument $dom, DOMElement $element, array $rule): void
    {
        switch ((string)$rule['transform']) {
            case 'rename':
                $newName = trim((string)($rule['to'] ?? ''));
                if ($newName === '' || $newName === $element->nodeName) {
                    break;
                }
                // Create a replacement element, copy attributes, move children.
                $replacement = $dom->createElement($newName);
                foreach ($element->attributes as $attribute) {
                    $replacement->setAttribute($attribute->name, $attribute->value);
                }
                while ($element->firstChild !== null) {
                    $replacement->appendChild($element->firstChild);
                }
                $element->parentNode?->replaceChild($replacement, $element);
                break;

            case 'set_value':
                $element->textContent = (string)($rule['value'] ?? '');
                break;

            case 'prefix_value':
                $element->textContent = ((string)($rule['prefix'] ?? '')) . $element->textContent;
                break;

            case 'regex_replace':
                $pattern     = (string)($rule['pattern'] ?? '');
                $replacement = (string)($rule['replacement'] ?? '');
                if ($pattern === '') {
                    break;
                }
                $result = @preg_replace($pattern, $replacement, $element->textContent);
                if ($result !== null) {
                    $element->textContent = $result;
                }
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Configuration loading
    // -------------------------------------------------------------------------

    /**
     * Load and cache a YAML field-mapping configuration by its file stem.
     *
     * Files must be located in:
     * `EXT:gedankenfolger_news_export/Configuration/FieldMappings/<name>.yaml`
     *
     * The mapping name is validated against a strict allowlist (`[a-zA-Z0-9_-]+`)
     * before it is used to construct a filesystem path, preventing path traversal.
     *
     * Returns an empty array when the file does not exist, is not readable, or
     * cannot be parsed as valid YAML.
     *
     * @param string $mappingName File stem (validated externally via {@see ExportOptions}).
     * @return array<string, mixed> Parsed YAML structure, or empty array on any error.
     */
    private function loadConfig(string $mappingName): array
    {
        if (array_key_exists($mappingName, $this->configCache)) {
            return $this->configCache[$mappingName];
        }

        // Double-check the name even though ExportOptions already sanitises it.
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mappingName)) {
            $this->configCache[$mappingName] = [];
            return [];
        }

        $filePath = ExtensionManagementUtility::extPath('gedankenfolger_news_export')
            . 'Configuration/FieldMappings/'
            . $mappingName
            . '.yaml';

        if (!is_file($filePath) || !is_readable($filePath)) {
            $this->configCache[$mappingName] = [];
            return [];
        }

        /** @var string|false $raw */
        $raw = GeneralUtility::getUrl($filePath);
        if ($raw === false || $raw === '') {
            $this->configCache[$mappingName] = [];
            return [];
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException) {
            $this->configCache[$mappingName] = [];
            return [];
        }

        $this->configCache[$mappingName] = is_array($parsed) ? $parsed : [];

        return $this->configCache[$mappingName];
    }

    // -------------------------------------------------------------------------
    // XML helpers
    // -------------------------------------------------------------------------

    /**
     * Parse an XML string into a DOMDocument with suppressed libxml warnings.
     *
     * Returns null if the string is not valid XML.
     *
     * @param string $xmlContent Raw XML string.
     */
    private function parseXml(string $xmlContent): ?DOMDocument
    {
        $dom                     = new DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;

        // Suppress libxml parse errors so that they do not bleed into PHP
        // output and are handled gracefully.
        $previousErrorHandling = libxml_use_internal_errors(true);

        $loaded = $dom->loadXML($xmlContent);

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        return $loaded ? $dom : null;
    }
}
