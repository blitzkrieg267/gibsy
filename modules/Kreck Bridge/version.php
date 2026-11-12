<?php
/*
Kreck Bridge — version file.

Gibbon's "Manage Modules" reads an Additional module's version from THIS file
(variable $moduleVersion), not from manifest.php, to decide whether an Update is
available. If this file is missing, Manage Modules shows "Version check failed"
and never offers the Update button — so this file must exist and must stay in
sync with $version in manifest.php.

$coreVersion is intentionally omitted (no minimum-core requirement), so the
module installs/updates on any supported Gibbon (tested v30.0.01 and v31).
*/

$moduleVersion = '0.5.01';
