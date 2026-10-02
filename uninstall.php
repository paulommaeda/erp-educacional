<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
// Deliberately preserve ERP tables, records, roles and capabilities on uninstall.
// Destructive deletion requires a separate, reviewed migration after export/backup.
