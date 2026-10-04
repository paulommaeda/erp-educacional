<?php
/**
 * Plugin Name: ERP Educacional
 * Description: Núcleo acadêmico e financeiro em tabelas próprias. Edição de homologação.
 * Plugin URI: https://github.com/paulommaeda/erp-educacional
 * Update URI: https://github.com/paulommaeda/erp-educacional
 * Version: 0.10.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Paulo Maeda
 * License: GPL-2.0-or-later
 * Text Domain: erp-educacional
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
define('EDERP_FILE', __FILE__);
define('EDERP_VERSION', '0.10.2');
require_once __DIR__ . '/autoload.php';
add_action('plugins_loaded', [EducacionalERP\Infrastructure\WordPress\Updates::class, 'boot']);
register_activation_hook(__FILE__, [EducacionalERP\Bootstrap::class, 'activate']);
add_action('plugins_loaded', static function (): void { (new EducacionalERP\Bootstrap())->boot(); });
