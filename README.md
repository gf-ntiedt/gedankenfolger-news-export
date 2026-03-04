<h1>TYPO3 Extension Gedankenfolger News Export<br/>(gedankenfolger-news-export)</h1>
<p>
    Backend module and CLI command for exporting <a href="https://github.com/georgringer/news" target="_blank">georgringer/news</a> records as <code>.t3d</code> files using TYPO3's built-in Import / Export engine (<code>EXT:impexp</code>).
</p>
<p>
    First of all many thanks to the whole TYPO3 community, all supporters of TYPO3.
    Especially to <a href="https://typo3.org/" target="_blank">TYPO3-Team</a> and <a href="https://www.gedankenfolger.de/" target="_blank">Gedankenfolger GmbH</a>.
</p>

<h3>
    Contents of this file
</h3>
<ol>
    <li>
        <a href="#features">Features</a>
    </li>
    <li>
        <a href="#requirements">Requirements</a>
    </li>
    <li>
        <a href="#install">Install</a>
    </li>
    <li>
        <a href="#usage">Usage</a>
    </li>
    <li>
        <a href="#options">Options</a>
    </li>
    <li>
        <a href="#cli">CLI Command</a>
    </li>
    <li>
        <a href="#fieldmapping">YAML Field Mapping</a>
    </li>
    <li>
        <a href="#notes">Notes</a>
    </li>
    <li>
        <a href="#noticetrademark">Notice on Logo / Trademark Use</a>
    </li>
</ol>
<hr/>
<h3 id="features">
    Features:
</h3>
<ol>
    <li>
        Backend module under <strong>Web › News Export</strong> — select a storage page in the page tree, preview matching news records in a paginated table, and download the export in one click
    </li>
    <li>
        Three export formats: XML (human-readable), T3D binary, and T3D compressed
    </li>
    <li>
        FAL file embedding — optionally embed referenced images and attachments base64-encoded directly inside the <code>.t3d</code> archive for a fully self-contained export
    </li>
    <li>
        ZIP download — download all locally stored FAL files referenced by the selected news records as a <code>.zip</code> archive
    </li>
    <li>
        Hidden-record filter — checkbox / CLI flag to exclude disabled (<code>hidden=1</code>) records from both the preview and the export
    </li>
    <li>
        YAML field-mapping — drop-in transformation configs that rename fields or rewrite values in the generated XML (useful for cross-version or cross-domain migrations)
    </li>
    <li>
        CLI command <code>vendor/bin/typo3 news:export</code> for scripted or Scheduler-based exports
    </li>
    <li>
        CSRF protection — backend form is protected via TYPO3's <code>FormProtectionFactory</code>
    </li>
    <li>
        No filesystem writes — the export stream is returned directly to the browser; nothing is stored on the server
    </li>
</ol>

<h3 id="requirements">
    Requirements
</h3>
<ul>
    <li><strong>TYPO3 CMS</strong>: <code>^13.0</code></li>
    <li><strong>PHP</strong>: <code>^8.1</code></li>
    <li><strong>typo3/cms-impexp</strong>: <code>^13.0</code> (ships with TYPO3 core)</li>
    <li><strong>georgringer/news</strong>: <code>^10.0</code> or <code>^13.0</code> (must be installed on the source system)</li>
</ul>

<h3 id="install">
    Install
</h3>
<ol>
    <li>
        Require the package via Composer:
        <br/><code>composer require gedankenfolger/gedankenfolger-news-export</code>
    </li>
    <li>
        Clear all TYPO3 caches:
        <br/><code>vendor/bin/typo3 cache:flush</code>
    </li>
    <li>
        The backend module appears automatically under <strong>Web › News Export</strong> for all backend users.
    </li>
</ol>

<h3 id="usage">
    Usage
</h3>
<ol>
    <li>
        Open <strong>Web › News Export</strong> in the TYPO3 backend.
    </li>
    <li>
        Select a storage page in the <strong>page tree</strong> (or enter PIDs manually in the form).
    </li>
    <li>
        The preview table below the form shows all news records on the selected page(s).
    </li>
    <li>
        Configure the export options and click one of the download buttons:
        <ul>
            <li><strong>Download .t3d export</strong> — streams the <code>.t3d</code> record export file</li>
            <li><strong>Download referenced files (.zip)</strong> — streams a ZIP of all locally stored FAL files (visible only when records are found)</li>
        </ul>
    </li>
    <li>
        Import the downloaded <code>.t3d</code> file via <strong>System › Import / Export › Import</strong>.
    </li>
</ol>

<h3 id="options">
    Options
</h3>

<h4>Export Form Options</h4>
<ul>
    <li><strong>Storage PID(s)</strong>: Comma-separated list of storage page UIDs. Defaults to the page currently selected in the page tree.</li>
    <li><strong>Specific news UIDs</strong>: Export only these records (PID filter is still applied). Leave empty to export all records on the selected page(s).</li>
    <li><strong>Export title</strong>: Optional human-readable title stored in the <code>.t3d</code> metadata header.</li>
    <li><strong>File type</strong>: <code>XML (.t3d)</code> (human-readable, default), <code>T3D – binary</code>, or <code>T3D compressed – binary + gzip</code>.</li>
    <li><strong>Embed FAL files</strong>: When enabled, the binary content of all referenced files (images, attachments) is base64-encoded and embedded directly inside the <code>.t3d</code> archive. Produces a fully self-contained archive but significantly increases file size.</li>
    <li><strong>Visible records only</strong>: Exclude hidden/disabled records (<code>hidden=1</code>) from both the preview and the export. Soft-deleted records (<code>deleted=1</code>) are always excluded regardless of this setting.</li>
    <li><strong>Field mapping</strong>: Optional YAML transformation config applied to the XML output before download. Only shown when mapping files exist in <code>Configuration/FieldMappings/</code>.</li>
</ul>

<h4>File References (FAL)</h4>
<p>When <strong>Embed FAL files</strong> is <strong>off</strong> (default), the archive contains <code>sys_file_reference</code> records but not the physical files. The Import module will show <code>LOST RELATION</code> warnings for <code>sys_file</code> entries — this is expected behaviour. On the target system:</p>
<ul>
    <li><strong>Same server</strong>: Relations resolve automatically because <code>sys_file</code> records already exist with the same UIDs.</li>
    <li><strong>Different server</strong>: Copy the files to <code>fileadmin/</code> at the same relative paths, use <strong>Embed FAL files</strong> for a self-contained archive, or use <strong>Download referenced files (.zip)</strong> to get the raw files for manual transfer.</li>
</ul>

<h4>Included Tables</h4>
<p>Every export always includes the following related tables (when referenced):</p>
<ul>
    <li><code>tx_news_domain_model_news</code> — Primary export target</li>
    <li><code>tx_news_domain_model_tag</code> — Tags</li>
    <li><code>tx_news_domain_model_link</code> — Related links</li>
    <li><code>sys_category</code> — Categories</li>
    <li><code>sys_file_reference</code> — FAL file references</li>
</ul>

<h3 id="cli">
    CLI Command
</h3>
<p><code>vendor/bin/typo3 news:export [options]</code></p>
<ul>
    <li><strong>--pid=&lt;int,...&gt;</strong>: Comma-separated storage PID(s) to export all news from.</li>
    <li><strong>--uid=&lt;int,...&gt;</strong>: Export only these news UIDs.</li>
    <li><strong>--output=&lt;path&gt;</strong> (<code>-o</code>): Output file path. Defaults to <code>news-export-&lt;timestamp&gt;.t3d</code> in the current working directory.</li>
    <li><strong>--type=&lt;format&gt;</strong> (<code>-t</code>): <code>xml</code> (default), <code>t3d</code>, or <code>t3d_compressed</code>.</li>
    <li><strong>--title=&lt;string&gt;</strong>: Title stored in the <code>.t3d</code> metadata header.</li>
    <li><strong>--field-map=&lt;name&gt;</strong>: YAML mapping config name (file stem without <code>.yaml</code> extension).</li>
    <li><strong>--include-files</strong>: Embed FAL file binaries (base64-encoded) inside the archive.</li>
    <li><strong>--exclude-disabled</strong>: Exclude hidden/disabled records (<code>hidden=1</code>) from the export.</li>
</ul>
<p>Examples:</p>
<pre><code># Export all news from PID 42 as XML
vendor/bin/typo3 news:export --pid=42

# Export with embedded files and compressed format
vendor/bin/typo3 news:export --pid=42 --type=t3d_compressed --include-files

# Export specific records to a custom path
vendor/bin/typo3 news:export --pid=42 --uid=101,102,103 --output=/var/backups/news.t3d

# Export only visible records
vendor/bin/typo3 news:export --pid=42 --exclude-disabled</code></pre>
<p>The command is tagged <code>schedulable: true</code> and can be configured as a recurring task in the <strong>TYPO3 Scheduler</strong>.</p>

<h3 id="fieldmapping">
    YAML Field Mapping
</h3>
<p>
    Drop a <code>*.yaml</code> file into <code>Configuration/FieldMappings/</code> to make it appear in the backend dropdown and as a valid <code>--field-map</code> value.
</p>
<p>Supported transforms:</p>
<ul>
    <li><strong>rename</strong>: Rename a field to a new name</li>
    <li><strong>set_value</strong>: Set a fixed value on all records</li>
    <li><strong>prefix_value</strong>: Prepend a string to the existing value</li>
    <li><strong>regex_replace</strong>: Apply a regular-expression replacement on the value</li>
</ul>
<p>
    The business fields of <code>tx_news_domain_model_news</code> are <strong>identical</strong> between <code>georgringer/news</code> 10.x and 13.x — no structural field mapping is required for cross-version migration. A field-mapping YAML is only needed for value-level transformations such as URL rewrites or slug prefixes.
</p>

<h3 id="notes">
    Notes
</h3>
<ul>
    <li>Soft-deleted records (<code>deleted=1</code>) are always excluded by <code>EXT:impexp</code> and cannot be included in an export.</li>
    <li>Remote-storage FAL files (S3, SFTP, etc.) are not included in the ZIP download — only files on local-driver storages are packaged.</li>
    <li>The <code>LOST RELATION (Path: /)</code> warning in the import preview refers to the page-tree path of <code>sys_file</code> records (which always have <code>pid=0</code> = root). It is not a filesystem path and is harmless on same-server imports.</li>
    <li>After installation or update, clear all TYPO3 caches: <code>vendor/bin/typo3 cache:flush</code></li>
</ul>

<h3 id="noticetrademark">
    Notice on Logo / Trademark Use
</h3>
<p>
The logo used in this extension is protected by copyright and, where applicable, trademark law and remains the exclusive property of Gedankenfolger GmbH.

Use of the logo is only permitted in the form provided here. Any changes, modifications, or adaptations of the logo, as well as its use in other projects, applications, or contexts, require the prior written consent of Gedankenfolger GmbH.

In forks, derivatives, or further developments of this extension, the logo may only be used if explicit consent has been granted by Gedankenfolger GmbH. Otherwise, the logo must be removed or replaced with an own, non-protected logo.

All other logos and icons bundled with this extension are either subject to the TYPO3 licensing terms (The MIT License (MIT), see https://typo3.org) or are in the public domain.
</p>
