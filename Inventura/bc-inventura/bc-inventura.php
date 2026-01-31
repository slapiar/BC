<?php
/**
 * Plugin Name: BC Inventúra (Breznianska cukráreň)
 * Description: Denné inventúrne hárky pre stánky (Prior) + príjem/predaj/akcie/odpis + export/print. Pripravené na sync z PWA (woo-search).
 * Version: 0.2.33-modular
 * Author: Slavio + Joyee
 */

if (!defined('ABSPATH')) exit;

define('BC_INV_VERSION', '0.2.33-modular');
define('BC_INV_PATH', plugin_dir_path(__FILE__));
define('BC_INV_URL', plugin_dir_url(__FILE__));
define('BC_INV_MODULES', BC_INV_PATH . 'modules/');

// Core traits
require_once BC_INV_MODULES . 'core/trait-core.php';
require_once BC_INV_MODULES . 'core/trait-db.php';
require_once BC_INV_MODULES . 'core/trait-cpt-admin.php';
require_once BC_INV_MODULES . 'core/trait-access.php';

// Admin UI trait
require_once BC_INV_MODULES . 'admin/trait-admin-ui.php';

// Services traits
require_once BC_INV_MODULES . 'services/trait-sheets.php';
require_once BC_INV_MODULES . 'services/trait-reservations.php';
require_once BC_INV_MODULES . 'services/trait-rest.php';
require_once BC_INV_MODULES . 'services/trait-upload.php';

class BC_Inventura {
  const CPT = 'bc_inventura';
  const REST_NS = 'bc-inventura/v1';

  // DB schema version (increment when dbDelta definitions change)
  const DB_VERSION = '0.2.6';

  use BC_Inv_Trait_Core;
  use BC_Inv_Trait_DB;
  use BC_Inv_Trait_CPT_Admin;
  use BC_Inv_Trait_Access;
  use BC_Inv_Trait_Admin_UI;
  use BC_Inv_Trait_Sheets;
  use BC_Inv_Trait_Reservations;
  use BC_Inv_Trait_REST;
  use BC_Inv_Trait_Upload;
}

// Bootstrap
add_action('plugins_loaded', ['BC_Inventura', 'init']);
register_activation_hook(__FILE__, ['BC_Inventura', 'activate']);
