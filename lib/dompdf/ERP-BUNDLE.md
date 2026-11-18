# PDF runtime

Dompdf 3.1.6 and its packaged runtime dependencies, from the official release:
https://github.com/dompdf/dompdf/releases/tag/v3.1.6

Original archive: `dompdf-3.1.6.zip`.
SHA-256: `05df8ee4907325ed2e09a139de9784325f761b43a049297155499270163ac94d`.

PHP source namespaces were prefixed with `EducacionalERP\Vendor` using PHP-Scoper 0.18.20, targeting PHP 8.1. Dynamic class strings were adjusted in Dompdf `Frame/Factory.php` (decorators, reflowers, positioners), FontLib `Font.php` (family + class suffix) and `TrueType/File.php` (directory/table types). The custom runtime loader replaces the packaged Composer loader to keep these classes isolated from other WordPress plugins. No renderer algorithms were changed. Non-PHP resources are unchanged. Original license and authorship files are preserved in the root and package directories.

`TrueType/File.php::getFontType()` removes the added prefix before deriving the original family from the class name. This preserves the upstream family lookup after isolation.

Build tool: https://github.com/humbug/php-scoper/releases/tag/0.18.20
SHA-256 of `php-scoper.phar`: `5e35e4a89ea53b2c70a19bfa8a856e4f9080aa12eb13a5bce650bb69d08eb122`.

The ERP renderer disables remote resources, embedded PHP and JavaScript. Body HTML is filtered separately before rendering. The school logo is loaded from validated local WordPress media and embedded in the document.
