<?php
if (!defined('ABSPATH')) exit;

trait BC_Inv_Trait_Admin_UI {

  
  // B2.3: Render system flags as small badges (SK)
  private static function render_flags_badges($system_flags): string {
    $flags = [];
    if (is_array($system_flags)) {
      $flags = $system_flags;
    } elseif (is_string($system_flags) && $system_flags !== '') {
      $dec = json_decode($system_flags, true);
      if (is_array($dec)) $flags = $dec;
      else {
        // fallback: comma-separated
        $flags = array_filter(array_map('trim', explode(',', $system_flags)));
      }
    }
    if (empty($flags)) return '';
    $map = [
      'NO_CONTACT'    => ['Bez kontaktu', 'warn'],
      'FAST_PICKUP'   => ['Rýchly odber', 'warn'],
      'HIGH_QTY'      => ['Veľa ks', 'warn'],
      'HIGH_VALUE'    => ['Vysoká suma', 'warn'],
      'NO_PICKUP_TIME'=> ['Chýba čas', 'bad'],
      'DUPLICATE'     => ['Duplicitná', 'bad'],
      'PAST_DUE'      => ['Po termíne', 'bad'],
      'TEST'          => ['Test', 'muted'],
    ];
    $html = '';
    foreach ($flags as $f) {
      $f = (string)$f;
      $label = $f;
      $lvl = 'muted';
      if (isset($map[$f])) { $label = $map[$f][0]; $lvl = $map[$f][1]; }
      $cls = 'bc-flag bc-flag--' . $lvl;
      $html .= '<span class="' . esc_attr($cls) . '" title="' . esc_attr($f) . '">' . esc_html($label) . '</span> ';
    }
    return trim($html);
  }

public static 

  /**
   * Ľudské vysvetlenia systémových značiek (flagov) – aby personál nemusel hádať.
   */
  function render_flags_help($system_flags): string {
    $flags = [];
    if (is_array($system_flags)) {
      $flags = $system_flags;
    } elseif (is_string($system_flags) && $system_flags !== '') {
      $dec = json_decode($system_flags, true);
      if (is_array($dec)) $flags = $dec;
      else $flags = array_filter(array_map('trim', explode(',', $system_flags)));
    }
    $flags = array_values(array_unique(array_filter($flags)));
    if (empty($flags)) return '';

    $dict = self::flags_dictionary();

    $out = '<div style="margin-top:6px;font-size:12px;line-height:1.35;color:#50575e;">';
    foreach ($flags as $f) {
      $f = (string)$f;
      $title = $dict[$f]['title'] ?? $f;
      $desc  = $dict[$f]['desc']  ?? 'Systémová značka – význam doplníme podľa praxe.';
      $out .= '<div style="margin:2px 0;"><strong>' . esc_html($title) . ':</strong> ' . esc_html($desc) . '</div>';
    }
    $out .= '</div>';
    return $out;
  }

  /**
   * Slovník flagov: názov + význam.
   * (Rozšíriteľné – keď pridáme ďalšie flagy, doplníme sem.)
   */
  private static function flags_dictionary(): array {
    return [
      'DUPLICATE' => [
        'title' => 'Možná duplicita',
        'desc'  => 'Rovnaký kontakt (email/telefón) má ďalšiu rezerváciu v blízkom čase (±2 hodiny).'
      ],
      'PAST_DUE' => [
        'title' => 'Čas v minulosti',
        'desc'  => 'Pickup čas je už v minulosti – skontroluj, či ide o preklep alebo dodatočný zápis.'
      ],
      'NO_CONTACT' => [
        'title' => 'Chýba kontakt',
        'desc'  => 'Nie je vyplnený email ani telefón – zákazníka nebude možné upozorniť.'
      ],
    ];
  }
  public static function admin_menu() {
    add_menu_page(
      'Inventúra',
      'Inventúra',
      'edit_posts',
      'bc-inventura',
      [__CLASS__, 'page_list'],
      'dashicons-clipboard',
      56
    );

    add_submenu_page(
      'bc-inventura',
      'Nový hárok',
      'Nový hárok',
      'edit_posts',
      'bc-inventura-new',
      [__CLASS__, 'page_new']
    );

    // Etapa 3.9: Zoznam prevádzok (pevné ID) – základ pre multiuser / rezervácie.
    add_submenu_page(
      'bc-inventura',
      'Prevádzky',
      'Prevádzky',
      'manage_options',
      'bc-inventura-stores',
      [__CLASS__, 'page_stores']
    );

    // Register audit submenu (if available)
    if (method_exists(__CLASS__, 'register_audit_menu')) {
      self::register_audit_menu();
    }

    // Etapa 4.6: Prístupy k prevádzkam (priradenie užívateľov a rolí ku konkrétnej prevádzke).
    add_submenu_page(
      'bc-inventura',
      'Prístupy',
      'Prístupy',
      'manage_options',
      'bc-inventura-access',
      [__CLASS__, 'page_store_access']
    );

// Etapa 4.0: Rezervácie (signál dopytu -> konverzia do Woo objednávky)
    add_submenu_page(
      'bc-inventura',
      'Rezervácie',
      'Rezervácie',
      'edit_posts',
      'bc-inventura-reservations',
      [__CLASS__, 'page_reservations']
    );



// Etapa B1: Nová rezervácia (admin formulár – nie len test)
add_submenu_page(
  'bc-inventura',
  'Nová rezervácia',
  'Nová rezervácia',
  'edit_posts',
  'bc-inventura-reservation-new',
  [__CLASS__, 'page_reservation_new']
);

// Skrytá stránka: Úprava rezervácie (otvára sa klikom zo zoznamu)
add_submenu_page(
  null,
  'Upraviť rezerváciu',
  'Upraviť rezerváciu',
  'edit_posts',
  'bc-inventura-reservation-edit',
  [__CLASS__, 'page_reservation_edit']
);

// Etapa 4.8: Kalendár rezervácií (týždenný prehľad) – pre plánovanie prípravy a výdaja.
add_submenu_page(
  'bc-inventura',
  'Kalendár',
  'Kalendár',
  'edit_posts',
  'bc-inventura-calendar',
  [__CLASS__, 'page_calendar']
);

    // Etapa A1/A2: Nastavenia + Email log
    add_submenu_page(
      'bc-inventura',
      'Nastavenia',
      'Nastavenia',
      'manage_options',
      'bc-inventura-settings',
      [__CLASS__, 'page_settings']
    );

    add_submenu_page(
      'bc-inventura',
      'Email log',
      'Email log',
      'manage_options',
      'bc-inventura-email-log',
      [__CLASS__, 'page_email_log']
    );


    // Natívny zoznam CPT (tu sú Bulk akcie ako "Ponechať len poslednú verziu")
    add_submenu_page(
      'bc-inventura',
      'Všetky hárky (WP zoznam)',
      'Všetky hárky (WP zoznam)',
      'edit_posts',
      'edit.php?post_type=' . self::CPT
    );

    add_submenu_page(
      'bc-inventura',
      'Dodacie listy (fotky)',
      'Dodacie listy (fotky)',
      'edit_posts',
      'bc-inventura-dodaky',
      [__CLASS__, 'page_dodaky']
    );

    add_submenu_page(
      null,
      'Tlač hárku',
      'Tlač hárku',
      'edit_posts',
      'bc-inventura-print',
      [__CLASS__, 'page_print']
    );
  }

  public static function admin_assets($hook) {
    if (strpos($hook, 'bc-inventura') === false) return;
    // admin.css lives in plugin root
    wp_enqueue_style('bc-inventura-admin', BC_INV_URL . 'admin.css', [], BC_INV_VERSION);
  }
  private static function render_store_role_select($name, $selected) {
    $name = (string)$name;
    $selected = (string)$selected;
    $roles = [
      'seller' => 'predavač',
      'manager' => 'manažér',
      'auditor' => 'audítor',
    ];
    $html = '<select name="' . esc_attr($name) . '">';
    foreach ($roles as $k => $label) {
      $html .= '<option value="' . esc_attr($k) . '"' . selected($selected, $k, false) . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

public static function page_list() {
    if (!self::current_user_can_manage()) {
      wp_die('Nemáš oprávnenie.');
    }

    $q = new WP_Query([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => 30,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    echo '<div class="wrap"><h1>Inventúrne hárky</h1>';
    echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=bc-inventura-new')) . '">Nový hárok</a></p>';

    echo '<table class="widefat striped"><thead><tr><th>Dátum</th><th>Názov</th><th>Prevádzka</th><th>Predavač</th><th>Verzia</th><th></th></tr></thead><tbody>';

    if (!$q->have_posts()) {
      echo '<tr><td colspan="5">Zatiaľ nič.</td></tr>';
    } else {
      foreach ($q->posts as $p) {
        $h = get_post_meta($p->ID, '_bc_inventura_header', true);
        $prev = is_array($h) && isset($h['prevadzka']) ? $h['prevadzka'] : '';
        $meno = is_array($h) && isset($h['meno']) ? $h['meno'] : '';
        $datum = is_array($h) && isset($h['datum']) ? $h['datum'] : get_the_date('Y-m-d', $p);
        $ver = (int)get_post_meta($p->ID, '_bc_sheet_version', true);
        $print_url = admin_url('admin.php?page=bc-inventura-print&id=' . intval($p->ID));
        echo '<tr>';
        echo '<td>' . esc_html($datum) . '</td>';
        echo '<td>' . esc_html($p->post_title) . '</td>';
        echo '<td>' . esc_html($prev) . '</td>';
        echo '<td>' . esc_html($meno) . '</td>';
        echo '<td>' . ($ver ? '#' . esc_html($ver) : '—') . '</td>';
        echo '<td><a class="button" target="_blank" href="' . esc_url($print_url) . '">Tlač</a></td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table></div>';
  }
  public static function page_reservations() {
    if (!self::current_user_can_manage()) {
      wp_die('Nemáš oprávnenie.');
    }
    try {
      global $wpdb;
    $tRes = self::table('reservations');

    // Only show stores the current user is allowed to see (store-scoped access).
    $stores = self::get_accessible_stores_map_for_user(get_current_user_id());
    $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    $has_store_param = isset($_GET['store_id']);
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';
    $flagged = isset($_GET['flagged']) ? (int)$_GET['flagged'] : 0;
    $flag = isset($_GET['flag']) ? sanitize_text_field($_GET['flag']) : '';

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : self::get_setting_reservations_default_limit();
    if ($limit < 10) $limit = 10; if ($limit > 500) $limit = 500;

    if (!$has_store_param && $store_id <= 0 && !empty($stores)) {
      $keys = array_keys($stores);
      $store_id = (int)$keys[0];
    }

    if (empty($stores)) {
      wp_die('Nemáš prístup k žiadnej prevádzke. Priradenie urobíš v menu Inventúra → Prístupy.');
    }

    if ($store_id > 0 && !self::current_user_has_store_access($store_id)) {
      wp_die('Nemáš prístup k tejto prevádzke.');
    }

    // Admin helper: create a test reservation quickly (for notebook debugging).
    if (isset($_GET['bc_action']) && $_GET['bc_action'] === 'add_test') {
      $nonce = isset($_GET['_bc_nonce']) ? sanitize_text_field($_GET['_bc_nonce']) : '';
      if (!$nonce || !wp_verify_nonce($nonce, 'bc_inv_add_test_reservation')) {
        wp_die('Neplatný nonce.');
      }
      if ($store_id <= 0) {
        add_settings_error('bc_inv', 'bc_inv_no_store', 'Vyber prevádzku pre test rezerváciu.', 'error');
      } else {
        $pickup_at = $date ? ($date . ' 14:00:00') : current_time('mysql');
        $new_id = self::create_test_reservation($store_id, $pickup_at);
        if ($new_id) {
          wp_safe_redirect(add_query_arg([
            'page' => 'bc-inventura-reservations',
            'store_id' => $store_id,
            'status' => $status,
            'date' => $date,
            'created' => (int)$new_id,
          ], admin_url('admin.php')));
          exit;
        }
        add_settings_error('bc_inv', 'bc_inv_test_fail', 'Test rezerváciu sa nepodarilo vytvoriť.', 'error');
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_inv_action'])) {
      check_admin_referer('bc_inv_res_actions');
      $act = sanitize_text_field($_POST['bc_inv_action']);
      $rid = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;

      if ($rid > 0) {
        $row = self::db_get_reservation($rid);
        if ($row && self::current_user_has_store_access((int)$row['store_id'])) {
          if ($act === 'set_status') {
            $new_status = isset($_POST['new_status']) ? sanitize_text_field($_POST['new_status']) : '';
            self::db_update_reservation($rid, [
              'status' => $new_status,
              'staff_user_id' => get_current_user_id(),
              'updated_at' => current_time('mysql'),
            ]);
          } elseif ($act === 'to_order' && self::is_woocommerce_active()) {
            self::convert_reservation_to_order_internal($rid);
          }
        }
      }
      wp_safe_redirect(add_query_arg(['page'=>'bc-inventura-reservations','store_id'=>$store_id,'status'=>$status,'date'=>$date], admin_url('admin.php')));
      exit;
    }

    // Lazy expiry: mark overdue reservations as expired (grace hours).
    if ($store_id > 0) {
      self::expire_overdue_reservations_for_store($store_id, self::get_setting_expiry_grace_hours());
    } else {
      foreach (array_keys($stores) as $sid) {
        self::expire_overdue_reservations_for_store((int)$sid, self::get_setting_expiry_grace_hours());
      }
    }

    
$is_recent = ($date === '');
    // Store filter: store_id>0 = konkrétna prevádzka, store_id==0 = všetky dostupné prevádzky.
    $store_ids = array_keys($stores);
    if (empty($store_ids)) { $store_ids = []; }

    if (!$is_recent) {
      $date_from = $date . ' 00:00:00';
      $date_to = $date . ' 23:59:59';

      if ($store_id > 0) {
        $where = "store_id=%d AND (pickup_at IS NULL OR (pickup_at >= %s AND pickup_at <= %s))";
        $params = [$store_id, $date_from, $date_to];
      } else {
        $in = implode(',', array_fill(0, count($store_ids), '%d'));
        $where = "store_id IN ($in) AND (pickup_at IS NULL OR (pickup_at >= %s AND pickup_at <= %s))";
        $params = array_merge($store_ids, [$date_from, $date_to]);
      }
      if ($status !== '') { $where .= " AND status=%s"; $params[] = $status; }
      // Flags filter
      if ($flagged) { $where .= " AND system_flags IS NOT NULL AND system_flags <> '' AND system_flags <> '[]'"; }
      if ($flag !== '') { $where .= " AND system_flags LIKE %s"; $params[] = '%"' . $wpdb->esc_like($flag) . '"%'; }

      $sql = $wpdb->prepare("SELECT * FROM $tRes WHERE $where ORDER BY pickup_at ASC, created_at ASC LIMIT " . (int)$limit, $params);
      $rows = $wpdb->get_results($sql, ARRAY_A);
    } else {
      // Recent mode: show last N reservations by creation time, no date filter.
      if ($store_id > 0) {
        $where = "store_id=%d";
        $params = [$store_id];
      } else {
        $in = implode(',', array_fill(0, count($store_ids), '%d'));
        $where = "store_id IN ($in)";
        $params = $store_ids;
      }
      if ($status !== '') { $where .= " AND status=%s"; $params[] = $status; }
      // Flags filter
      if ($flagged) { $where .= " AND system_flags IS NOT NULL AND system_flags <> '' AND system_flags <> '[]'"; }
      if ($flag !== '') { $where .= " AND system_flags LIKE %s"; $params[] = '%"' . $wpdb->esc_like($flag) . '"%'; }

      $sql = $wpdb->prepare("SELECT * FROM $tRes WHERE $where ORDER BY created_at DESC LIMIT " . (int)$limit, $params);
      $rows = $wpdb->get_results($sql, ARRAY_A);
    }

echo '<div class="wrap"><h1>Rezervácie</h1>';

    echo '<p>'
      . '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=bc-inventura-reservation-new')) . '">Nová rezervácia</a> '
      . '<a class="button" href="' . esc_url(add_query_arg(['action'=>'create_test','store_id'=>$store_id,'date'=>$date], admin_url('admin.php?page=bc-inventura-reservations'))) . '">Pridať test</a>'
      . '</p>';

    settings_errors('bc_inv');
    if (isset($_GET['created'])) {
      echo '<div class="notice notice-success"><p>Test rezervácia vytvorená. ID: <strong>' . (int)$_GET['created'] . '</strong></p></div>';
    }

    echo '<form method="get" style="margin: 10px 0;">';
    echo '<input type="hidden" name="page" value="bc-inventura-reservations" />';
    echo '<label>Prevádzka: <select name="store_id">';
    echo '<option value="0"' . selected($store_id, 0, false) . '>Všetky</option>';
    foreach ($stores as $sid => $sname) {
      echo '<option value="' . (int)$sid . '"' . selected($store_id, (int)$sid, false) . '>' . esc_html($sname) . '</option>';
    }
    echo '</select></label> ';
    echo '<label>Počet (N): <select name="limit">';
    foreach ([20,50,100,200,500] as $n) {
      echo '<option value="' . (int)$n . '"' . selected($limit, (int)$n, false) . '>' . (int)$n . '</option>';
    }
    echo '</select></label> ';
    echo '<label>Dátum: <input type="date" name="date" value="' . esc_attr($date) . '" /></label> ';
    echo '<span class="description" style="margin-left:6px;">(ponechaj prázdne = posledné rezervácie)</span> ';
    echo '<label>Status: <select name="status">';
    $opts = ['' => 'všetky', 'submitted'=>'submitted', 'confirmed'=>'confirmed', 'ready'=>'ready', 'picked_up'=>'picked_up', 'cancelled'=>'cancelled', 'expired'=>'expired'];
    foreach ($opts as $k=>$v) {
      echo '<option value="' . esc_attr($k) . '"' . selected($status, $k, false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select></label> ';
    echo '<label style="margin-left:8px;"><input type="checkbox" name="flagged" value="1" ' . checked($flagged, 1, false) . ' /> Len označené</label> ';
    echo '<label>Značka: <select name="flag">';
    $flag_opts = ['' => 'všetky', 'DUPLICATE'=>'DUPLICATE', 'PAST_DUE'=>'PAST_DUE'];
    foreach ($flag_opts as $fk=>$fv) {
      echo '<option value="' . esc_attr($fk) . '"' . selected($flag, $fk, false) . '>' . esc_html($fv) . '</option>';
    }
    echo '</select></label> ';
    echo '<button class="button">Filtrovať</button>';

    // Quick test button (creates a sample reservation for current filters).
    if ($store_id > 0) {
      $test_url = wp_nonce_url(
        add_query_arg([
          'page' => 'bc-inventura-reservations',
          'bc_action' => 'add_test',
          'store_id' => $store_id,
          'status' => $status,
          'date' => $date,
        ], admin_url('admin.php')),
        'bc_inv_add_test_reservation',
        '_bc_nonce'
      );
      echo ' <a class="button button-secondary" href="' . esc_url($test_url) . '">Pridať test</a>';
    }
    echo '</form>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>ID</th><th>Vytvoril</th><th>Pickup</th><th>Status</th><th>Dôvera</th><th>Značky</th><th>Položky</th><th>Suma</th><th>Akcia</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
      echo '<tr><td colspan="9">Zatiaľ nič.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $rid = (int)$r['id'];
        $items = self::db_get_reservation_items($rid);

  $pre_save_warning = false;
  $critical = [];
        $items_txt = [];
        foreach ($items as $it) {
          $items_txt[] = $it['name'] . ' × ' . rtrim(rtrim(number_format((float)$it['qty'], 3, '.', ''), '0'), '.');
        }
        $confidence = (int)$r['confidence'];
        $badge = $confidence >= 70 ? 'exp-ok' : ($confidence >= 40 ? 'exp-3' : 'exp-minus-1');

        echo '<tr>';
          $edit_url = admin_url('admin.php?page=bc-inventura-reservation-edit&id=' . (int)$rid);
        echo '<td><a href="' . esc_url($edit_url) . '"><strong>#' . (int)$rid . '</strong></a></td>';

        // Creator (who created the reservation)
        $creator_id = (int)($r['created_by_user_id'] ?? 0);
        if ($creator_id <= 0) {
          $creator_id = (int)($r['staff_user_id'] ?? 0);
        }
        $creator_label = 'Systém';
        $creator_title = '';
        $creator_url = '';
        if ($creator_id > 0) {
          $u = get_userdata($creator_id);
          if ($u) {
            $creator_label = (string)$u->display_name;
            $creator_title = (string)$u->user_login;
            if (current_user_can('manage_options')) {
              $creator_url = admin_url('user-edit.php?user_id=' . (int)$creator_id);
            }
          } else {
            $creator_label = '#' . (int)$creator_id;
          }
        }
        if ($creator_url) {
          echo '<td><a href="' . esc_url($creator_url) . '" title="' . esc_attr($creator_title) . '">' . esc_html($creator_label) . '</a></td>';
        } else {
          echo '<td title="' . esc_attr($creator_title) . '">' . esc_html($creator_label) . '</td>';
        }
        echo '<td>' . esc_html($r['pickup_at'] ?: '-') . '</td>';
        echo '<td><strong>' . esc_html($r['status']) . '</strong></td>';
        echo '<td><span class="' . esc_attr($badge) . '">' . $confidence . '%</span></td>';
        $flags = [];
        if (!empty($r['system_flags'])) {
          $dec = json_decode((string)$r['system_flags'], true);
          if (is_array($dec)) $flags = $dec;
        }
        $flags_txt = '';
        if (!empty($flags)) {
          $flags_txt = implode(', ', array_slice(array_map('strval', $flags), 0, 5));
        }
        echo '<td>' . self::render_flags_badges($r['system_flags']) . '</td>';
        echo '<td>' . esc_html(implode(', ', $items_txt)) . '</td>';
        echo '<td>' . esc_html(number_format((float)$r['total_amount'], 2, ',', ' ')) . ' ' . esc_html($r['currency']) . '</td>';
        echo '<td>';
        echo '<a class="button button-small" href="' . esc_url($edit_url) . '" style="margin-right:6px;">Upraviť</a>';
        echo '<form method="post" style="display:flex; gap:6px; align-items:center;">';
        wp_nonce_field('bc_inv_res_actions');
        echo '<input type="hidden" name="reservation_id" value="' . $rid . '" />';
        echo '<select name="new_status">';
        foreach (['submitted','confirmed','ready','picked_up','cancelled','expired'] as $st) {
          echo '<option value="' . esc_attr($st) . '"' . selected($r['status'], $st, false) . '>' . esc_html($st) . '</option>';
        }
        echo '</select>';
        echo '<button class="button" name="bc_inv_action" value="set_status">Uložiť</button>';
        if (self::is_woocommerce_active()) {
          echo '<button class="button" name="bc_inv_action" value="to_order" title="Preklopiť do Woo objednávky">→ Woo</button>';
        }
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';
    echo '<p style="margin-top:10px;color:#666">Pozn.: teraz už zobrazujeme aj systémové značky (flags) a základné e-mail notifikácie. Ďalší krok: kalendár + expirácia cez cron + PWA/REST.</p>';
    echo '</div>';


  } catch (Throwable $e) {
    echo '<div class="wrap"><h1>Rezervácie</h1>';

    echo '<p>'
      . '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=bc-inventura-reservation-new')) . '">Nová rezervácia</a> '
      . '<a class="button" href="' . esc_url(add_query_arg(['action'=>'create_test','store_id'=>$store_id,'date'=>$date], admin_url('admin.php?page=bc-inventura-reservations'))) . '">Pridať test</a>'
      . '</p>';
    echo '<div class="notice notice-error"><p><strong>Chyba stránky Rezervácie</strong></p>';
    echo '<p>' . esc_html($e->getMessage()) . '</p>';
    echo '<p style="color:#666">File: ' . esc_html($e->getFile()) . ' (line ' . (int)$e->getLine() . ')</p>';
    echo '</div></div>';
  }
}




public static function page_reservation_new() {
  if (!self::current_user_can_manage()) {
    wp_die('Nemáš oprávnenie.');
  }

  $uid = (int)get_current_user_id();
  $stores = self::get_accessible_stores_map_for_user($uid);
  if (empty($stores)) {
    wp_die('Nemáš prístup k žiadnej prevádzke. Priradenie urobíš v menu Inventúra → Prístupy.');
  }

  $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    $has_store_param = isset($_GET['store_id']);
  if ($store_id <= 0) {
    $keys = array_keys($stores);
    $store_id = (int)$keys[0];
  }
  if ($store_id > 0 && !self::current_user_has_store_access($store_id)) {
    wp_die('Nemáš prístup k tejto prevádzke.');
  }


  // Form state (B2.3b warnings)
  $form = [
    'store_id' => $store_id,
    'pickup_at' => '',
    'customer_wp_user_id' => 0,
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'customer_note' => '',
    'internal_note' => '',
  ];
  $form_items = [];
  $force_needed = false;

  // Handle POST
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_inv_action']) && $_POST['bc_inv_action'] === 'create_admin_reservation') {
    check_admin_referer('bc_inv_create_admin_reservation');

    $store_id = isset($_POST['store_id']) ? (int)$_POST['store_id'] : $store_id;
    if ($store_id <= 0) {
      add_settings_error('bc_inv', 'bc_inv_store', 'Chýba prevádzka.', 'error');
    } elseif (!current_user_can('manage_options') && !self::current_user_has_store_access($store_id)) {
      add_settings_error('bc_inv', 'bc_inv_store', 'Nemáš prístup k tejto prevádzke.', 'error');
    }

    $pickup_at = isset($_POST['pickup_at']) ? sanitize_text_field((string)$_POST['pickup_at']) : '';

    $customer_wp_user_id = isset($_POST['customer_wp_user_id']) ? (int)$_POST['customer_wp_user_id'] : 0;
    $customer_name = isset($_POST['customer_name']) ? sanitize_text_field((string)$_POST['customer_name']) : '';
    $customer_email = isset($_POST['customer_email']) ? sanitize_email((string)$_POST['customer_email']) : '';
    $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field((string)$_POST['customer_phone']) : '';
    $customer_note = isset($_POST['customer_note']) ? wp_kses_post((string)$_POST['customer_note']) : '';
    $internal_note = isset($_POST['internal_note']) ? sanitize_textarea_field((string)$_POST['internal_note']) : '';

    $items = [];
    $pids = isset($_POST['item_product_id']) && is_array($_POST['item_product_id']) ? $_POST['item_product_id'] : [];
    $names = isset($_POST['item_name']) && is_array($_POST['item_name']) ? $_POST['item_name'] : [];
    $plus = isset($_POST['item_plu']) && is_array($_POST['item_plu']) ? $_POST['item_plu'] : [];
    $qtys = isset($_POST['item_qty']) && is_array($_POST['item_qty']) ? $_POST['item_qty'] : [];
    $prices = isset($_POST['item_unit_price']) && is_array($_POST['item_unit_price']) ? $_POST['item_unit_price'] : [];
    $notes = isset($_POST['item_note']) && is_array($_POST['item_note']) ? $_POST['item_note'] : [];

    $n = max(count($pids), count($names), count($qtys));
    for ($i=0; $i<$n; $i++) {
      $pid = isset($pids[$i]) ? (int)$pids[$i] : 0;
      $nm = isset($names[$i]) ? sanitize_text_field((string)$names[$i]) : '';
      $plu = isset($plus[$i]) ? sanitize_text_field((string)$plus[$i]) : '';
      $qty = isset($qtys[$i]) ? (float)str_replace(',', '.', (string)$qtys[$i]) : 0.0;
      $unit = isset($prices[$i]) ? (float)str_replace(',', '.', (string)$prices[$i]) : 0.0;
      $note = isset($notes[$i]) ? sanitize_text_field((string)$notes[$i]) : '';

      if ($qty <= 0) continue;
      if ($pid <= 0 && $nm === '') continue;

      $items[] = [
        'wc_product_id' => $pid > 0 ? $pid : null,
        'plu' => $plu !== '' ? $plu : null,
        'name' => $nm,
        'qty' => $qty,
        'unit_price' => $unit,
        'note' => $note !== '' ? $note : null,
      ];
    }

    $payload = [
      'store_id' => $store_id,
      'pickup_at' => $pickup_at,
      'customer_wp_user_id' => $customer_wp_user_id,
      'customer_name' => $customer_name,
      'customer_email' => $customer_email,
      'customer_phone' => $customer_phone,
      'customer_note' => $customer_note,
      'internal_note' => $internal_note,
      'items' => $items,
    ];

    // Persist form values for re-render on warnings/errors
    $form = $payload;
    $form_items = $items;

    // B2.3b: Pre-save warning for critical flags (requires explicit confirm)
    $force = isset($_POST['bc_inv_force']) && (int)$_POST['bc_inv_force'] === 1;

    // Build eval items with line_total for scoring/flags
    $eval_items = [];
    foreach ($items as $it) {
      $qty = isset($it['qty']) ? (float)$it['qty'] : 0.0;
      $unit = isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0;
      $eval_items[] = [
        'name' => (string)($it['name'] ?? ''),
        'qty' => $qty,
        'unit_price' => $unit,
        'line_total' => $qty * $unit,
      ];
    }

    $eval = self::evaluate_reservation([
      'store_id' => $store_id,
      'pickup_at' => $pickup_at,
      'status' => 'submitted',
      'customer_wp_user_id' => $customer_wp_user_id,
      'customer_name' => $customer_name,
      'customer_email' => $customer_email,
      'customer_phone' => $customer_phone,
      'customer_note' => $customer_note,
    ], $eval_items);

    $flags = isset($eval['flags']) && is_array($eval['flags']) ? $eval['flags'] : [];
    $critical = array_values(array_intersect($flags, ['DUPLICATE', 'NO_CONTACT', 'PAST_DUE']));

    if (!empty($critical) && !$force) {
      $force_needed = true;
      add_settings_error(
        'bc_inv',
        'bc_inv_pre_save_warning',
        'Pozor: rezervácia má rizikové značky: <strong>' . esc_html(implode(', ', $critical)) . '</strong>. Skontroluj údaje. Ak chceš pokračovať aj tak, klikni <strong>Vytvoriť aj tak</strong>.',
        'error'
      );
    } else {
          $created = self::create_admin_reservation($payload);
    if (is_wp_error($created)) {
      add_settings_error('bc_inv', 'bc_inv_create_error', $created->get_error_message(), 'error');
    } else {
      wp_safe_redirect(admin_url('admin.php?page=bc-inventura-reservations&created=' . (int)$created));
      exit;
    }
    }
  }

  // Products for dropdown (optional)
  $products = [];
  if (function_exists('wc_get_products')) {
    $products = wc_get_products([
      'limit' => 60,
      'status' => 'publish',
      'orderby' => 'title',
      'order' => 'ASC',
    ]);
  }

  echo '<div class="wrap"><h1>Nová rezervácia</h1>';
  settings_errors('bc_inv');

  echo '<form method="post">';
  wp_nonce_field('bc_inv_create_admin_reservation');
  echo '<input type="hidden" name="bc_inv_action" value="create_admin_reservation" />';

  echo '<table class="form-table"><tbody>';

  echo '<tr><th scope="row">Prevádzka</th><td><select name="store_id">';
  foreach ($stores as $sid => $sname) {
    echo '<option value="' . (int)$sid . '"' . selected($store_id, (int)$sid, false) . '>' . esc_html($sname) . '</option>';
  }
  echo '</select>';
  echo '<p class="description">Prevádzka je daná podľa výberu v URL. Ak chceš inú, otvor stránku s parametrom store_id.</p></td></tr>';

  $dt_default = !empty($form['pickup_at']) ? (string)$form['pickup_at'] : date('Y-m-d\TH:i', time() + 3600);
  echo '<tr><th scope="row">Vyzdvihnutie</th><td><input type="datetime-local" name="pickup_at" value="' . esc_attr($dt_default) . '" required />'
    . '<p class="description">Minimálny čas vyzdvihnutia: <strong>' . esc_html((string)self::get_setting_min_pickup_minutes()) . '</strong> min.</p>'
    . '</td></tr>';

  echo '<tr><th scope="row">Zákazník (WP user ID)</th><td><input type="number" name="customer_wp_user_id" min="0" step="1" placeholder="0 = neregistrovaný" value="' . esc_attr((string)($form['customer_wp_user_id'] ?? 0)) . '" /></td></tr>';
  echo '<tr><th scope="row">Meno</th><td><input type="text" name="customer_name" class="regular-text" value="' . esc_attr((string)($form['customer_name'] ?? '')) . '" /></td></tr>';
  echo '<tr><th scope="row">Email</th><td><input type="email" name="customer_email" class="regular-text" value="' . esc_attr((string)($form['customer_email'] ?? '')) . '" /></td></tr>';
  echo '<tr><th scope="row">Telefón</th><td><input type="text" name="customer_phone" class="regular-text" value="' . esc_attr((string)($form['customer_phone'] ?? '')) . '" /></td></tr>';

  echo '<tr><th scope="row">Poznámka zákazníka</th><td><textarea name="customer_note" rows="3" class="large-text">' . esc_textarea((string)($form['customer_note'] ?? '')) . '</textarea></td></tr>';
  echo '<tr><th scope="row">Interná poznámka</th><td><textarea name="internal_note" rows="3" class="large-text">' . esc_textarea((string)($form['internal_note'] ?? '')) . '</textarea></td></tr>';

  echo '</tbody></table>';

  echo '<h2>Položky</h2>';
  echo '<table class="widefat striped" id="bc-inv-items"><thead><tr><th>Produkt (Woo)</th><th>PLU</th><th>Názov (ak bez Woo)</th><th>Množstvo</th><th>Cena/ks (€)</th><th>Pozn.</th><th></th></tr></thead><tbody>';

    $render_row = function($vals) use ($products) {
    $pid_sel = isset($vals['wc_product_id']) ? (int)$vals['wc_product_id'] : 0;
    $plu_val = isset($vals['plu']) ? (string)$vals['plu'] : '';
    $name_val = isset($vals['name']) ? (string)$vals['name'] : '';
    $qty_val = isset($vals['qty']) ? (string)$vals['qty'] : '';
    $unit_val = isset($vals['unit_price']) ? (string)$vals['unit_price'] : '';
    $note_val = isset($vals['note']) ? (string)$vals['note'] : '';

    echo '<tr>';
    echo '<td><select name="item_product_id[]"><option value="0">—</option>';
    if (!empty($products)) {
      foreach ($products as $p) {
        $pid = (int)$p->get_id();
        $title = (string)$p->get_name();
        $price = (float)$p->get_price();
        echo '<option value="' . $pid . '" data-price="' . esc_attr($price) . '"' . selected($pid_sel, $pid, false) . '>' . esc_html($title) . '</option>';
      }
    }
    echo '</select></td>';
    echo '<td><input type="text" name="item_plu[]" style="width:110px" value="' . esc_attr($plu_val) . '" /></td>';
    echo '<td><input type="text" name="item_name[]" style="width:240px" placeholder="ak bez Woo produktu" value="' . esc_attr($name_val) . '" /></td>';
    echo '<td><input type="text" name="item_qty[]" style="width:90px" placeholder="1" value="' . esc_attr($qty_val) . '" /></td>';
    echo '<td><input type="text" name="item_unit_price[]" style="width:110px" placeholder="0" value="' . esc_attr($unit_val) . '" /></td>';
    echo '<td><input type="text" name="item_note[]" style="width:140px" value="' . esc_attr($note_val) . '" /></td>';
    echo '<td><button type="button" class="button bc-inv-remove-row">–</button></td>';
    echo '</tr>';
  };
  $rows = !empty($form_items) ? $form_items : [];
  if (count($rows) < 2) {
    for ($k=count($rows); $k<2; $k++) $rows[] = [];
  }
  foreach ($rows as $r) {
    $render_row($r);
  }

  echo '</tbody></table>';
  echo '<p><button type="button" class="button" id="bc-inv-add-row">Pridať položku</button></p>';

  echo '<p>';
  if ($force_needed) {
    echo '<button class="button button-secondary" type="submit">Skontrolovať</button> ';
    echo '<button class="button button-primary" type="submit" name="bc_inv_force" value="1">Vytvoriť aj tak</button> ';
  } else {
    echo '<button class="button button-primary" type="submit">Vytvoriť rezerváciu</button> ';
  }

  echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=bc-inventura-reservations&store_id=' . (int)$store_id)) . '">Späť</a></p>';
  echo '</form>';

  echo '<script>
    (function(){
      function bindRow(row){
        var sel = row.querySelector("select[name=\"item_product_id[]\"]");
        var price = row.querySelector("input[name=\"item_unit_price[]\"]");
        if(sel && price){
          sel.addEventListener("change", function(){
            var opt = sel.options[sel.selectedIndex];
            var p = opt && opt.getAttribute("data-price");
            if(p && (price.value==="" || price.value==="0")) price.value = p;
          });
        }
        var btn = row.querySelector(".bc-inv-remove-row");
        if(btn){
          btn.addEventListener("click", function(){
            var tbody = document.querySelector("#bc-inv-items tbody");
            if(tbody && tbody.children.length>1){
              row.remove();
            }
          });
        }
      }
      document.querySelectorAll("#bc-inv-items tbody tr").forEach(bindRow);
      var add = document.getElementById("bc-inv-add-row");
      if(add){
        add.addEventListener("click", function(){
          var tbody = document.querySelector("#bc-inv-items tbody");
          if(!tbody) return;
          var tpl = tbody.children[0].cloneNode(true);
          tpl.querySelectorAll("input").forEach(function(i){ i.value=""; });
          var s = tpl.querySelector("select");
          if(s) s.value="0";
          tbody.appendChild(tpl);
          bindRow(tpl);
        });
      }
    })();
  </script>';

  echo '</div>';
}


// Etapa B2 UX: Úprava rezervácie (detail) – otvorí sa kliknutím zo zoznamu
public static function page_reservation_edit() {
  if (!self::current_user_can_manage()) {
    wp_die('Nemáš oprávnenie.');
  }

  global $wpdb;
  $rid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($rid <= 0) wp_die('Chýba ID rezervácie.');

  $row = self::db_get_reservation($rid);
  if (!$row) wp_die('Rezervácia neexistuje.');

  $store_id = (int)($row['store_id'] ?? 0);
  if ($store_id > 0 && !self::current_user_has_store_access($store_id)) {
    wp_die('Nemáš prístup k tejto prevádzke.');
  }

  $stores = self::get_accessible_stores_map_for_user(get_current_user_id());
  $store_name = isset($stores[$store_id]) ? $stores[$store_id] : ('Prevádzka #' . $store_id);

  $items = self::db_get_reservation_items($rid);

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_inv_edit_res'])) {
    check_admin_referer('bc_inv_res_edit_' . $rid);

    $pickup_at = isset($_POST['pickup_at']) ? sanitize_text_field((string)$_POST['pickup_at']) : '';
    $status = isset($_POST['status']) ? sanitize_text_field((string)$_POST['status']) : 'submitted';
    $customer_name = isset($_POST['customer_name']) ? sanitize_text_field((string)$_POST['customer_name']) : '';
    $customer_email = isset($_POST['customer_email']) ? sanitize_email((string)$_POST['customer_email']) : '';
    $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field((string)$_POST['customer_phone']) : '';
    $customer_note = isset($_POST['customer_note']) ? wp_kses_post((string)$_POST['customer_note']) : '';
    $internal_note = isset($_POST['internal_note']) ? wp_kses_post((string)$_POST['internal_note']) : '';

    $names = isset($_POST['item_name']) && is_array($_POST['item_name']) ? $_POST['item_name'] : [];
    $qtys  = isset($_POST['item_qty']) && is_array($_POST['item_qty']) ? $_POST['item_qty'] : [];
    $prices= isset($_POST['item_price']) && is_array($_POST['item_price']) ? $_POST['item_price'] : [];
    $wcs   = isset($_POST['item_wc']) && is_array($_POST['item_wc']) ? $_POST['item_wc'] : [];
    $plus  = isset($_POST['item_plu']) && is_array($_POST['item_plu']) ? $_POST['item_plu'] : [];
    $notes = isset($_POST['item_note']) && is_array($_POST['item_note']) ? $_POST['item_note'] : [];

    $new_items = [];
    $total = 0.0;
    $max = max(count($names), count($qtys), count($prices));
    for ($i=0; $i<$max; $i++) {
      $name = isset($names[$i]) ? sanitize_text_field((string)$names[$i]) : '';
      if ($name === '') continue;
      $qty = isset($qtys[$i]) ? (float)str_replace(',', '.', (string)$qtys[$i]) : 0.0;
      $price = isset($prices[$i]) ? (float)str_replace(',', '.', (string)$prices[$i]) : 0.0;
      $line = $qty * $price;
      $total += $line;

      $new_items[] = [
        'wc_product_id' => isset($wcs[$i]) && $wcs[$i] !== '' ? (int)$wcs[$i] : null,
        'plu' => isset($plus[$i]) ? sanitize_text_field((string)$plus[$i]) : '',
        'name' => $name,
        'qty' => $qty,
        'unit_price' => $price,
        'line_total' => $line,
        'note' => isset($notes[$i]) ? sanitize_text_field((string)$notes[$i]) : '',
      ];
    }

    // Evaluate confidence/flags (B2.3) + save
    $eval = self::evaluate_reservation([
      'store_id' => $store_id,
      'pickup_at' => $pickup_at,
      'status' => $status,
      'customer_name' => $customer_name,
      'customer_email' => $customer_email,
      'customer_phone' => $customer_phone,
      'customer_note' => $customer_note,
    ], $new_items);

    // Update reservation row
    self::db_update_reservation($rid, [
      'pickup_at' => $pickup_at !== '' ? $pickup_at : null,
      'status' => $status,
      'customer_name' => $customer_name,
      'customer_email' => $customer_email,
      'customer_phone' => $customer_phone,
      'customer_note' => $customer_note,
      'internal_note' => $internal_note,
      'total_amount' => $total,
      'confidence' => (int)($eval['confidence'] ?? 0),
      'system_flags' => wp_json_encode($eval['flags'] ?? []),
      'staff_user_id' => get_current_user_id(),
      'updated_at' => current_time('mysql'),
    ]);

    // Replace items
    $tItems = self::table('reservation_items');
    $wpdb->delete($tItems, ['reservation_id' => $rid]);
    foreach ($new_items as $it) {
      $it['reservation_id'] = $rid;
      $wpdb->insert($tItems, $it);
    }

    wp_safe_redirect(add_query_arg(['page'=>'bc-inventura-reservation-edit','id'=>$rid,'updated'=>1], admin_url('admin.php')));
    exit;
  }

  // Reload after update
  $row = self::db_get_reservation($rid);
  $items = self::db_get_reservation_items($rid);

  $back_url = admin_url('admin.php?page=bc-inventura-reservations&store_id=' . (int)$store_id);

  echo '<div class="wrap"><h1>Upraviť rezerváciu <strong>#' . (int)$rid . '</strong></h1>';
  echo '<p><a class="button" href="' . esc_url($back_url) . '">← Späť na zoznam</a></p>';

  if (isset($_GET['updated'])) {
    echo '<div class="notice notice-success"><p>Uložené.</p></div>';
  }

  if (!empty($pre_save_warning) && !empty($critical)) {
    echo '<div class="notice notice-error"><p><strong>Pozor:</strong> rezervácia má rizikové značky: <strong>' . esc_html(implode(', ', $critical)) . '</strong>. Ak chceš pokračovať aj tak, klikni <strong>Uložiť aj tak</strong>.</p></div>';
  }

  echo '<p>Prevádzka: <strong>' . esc_html($store_name) . '</strong></p>';


  // ===== UX: Info panel (read-only) =====
  $created_by_id = (int)($row['created_by_user_id'] ?? 0);
  $created_by_label = 'Systém';
  $created_by_login = '';
  $created_by_url = '';
  if ($created_by_id > 0) {
    $u = get_userdata($created_by_id);
    if ($u) {
      $created_by_label = (string)$u->display_name;
      $created_by_login = (string)$u->user_login;
      if (current_user_can('manage_options')) {
        $created_by_url = admin_url('user-edit.php?user_id=' . (int)$created_by_id);
      }
    } else {
      $created_by_label = '#' . (int)$created_by_id;
    }
  }

  $updated_by_id = (int)($row['staff_user_id'] ?? 0);
  $updated_by_label = $updated_by_id > 0 ? ('#' . $updated_by_id) : '-';
  $updated_by_login = '';
  $updated_by_url = '';
  if ($updated_by_id > 0) {
    $uu = get_userdata($updated_by_id);
    if ($uu) {
      $updated_by_label = (string)$uu->display_name;
      $updated_by_login = (string)$uu->user_login;
      if (current_user_can('manage_options')) {
        $updated_by_url = admin_url('user-edit.php?user_id=' . (int)$updated_by_id);
      }
    }
  }

  $created_at = !empty($row['created_at']) ? (string)$row['created_at'] : '-';
  $updated_at = !empty($row['updated_at']) ? (string)$row['updated_at'] : '-';
  $confidence = (int)($row['confidence'] ?? 0);

  echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:10px 12px;max-width:980px;margin:10px 0 16px;">';
  echo '<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start;">';

  echo '<div><div style="font-size:12px;color:#646970;">Vytvoril</div>';
  if ($created_by_url) {
    echo '<div><a href="' . esc_url($created_by_url) . '" title="' . esc_attr($created_by_login) . '"><strong>' . esc_html($created_by_label) . '</strong></a></div>';
  } else {
    echo '<div title="' . esc_attr($created_by_login) . '"><strong>' . esc_html($created_by_label) . '</strong></div>';
  }
  echo '</div>';

  echo '<div><div style="font-size:12px;color:#646970;">Vytvorené</div><div><strong>' . esc_html($created_at) . '</strong></div></div>';

  echo '<div><div style="font-size:12px;color:#646970;">Posledná zmena</div>';
  echo '<div><strong>' . esc_html($updated_at) . '</strong>';
  if ($updated_by_id > 0) {
    echo '<span style="margin-left:8px;color:#646970;">(';
    if ($updated_by_url) {
      echo '<a href="' . esc_url($updated_by_url) . '" title="' . esc_attr($updated_by_login) . '">' . esc_html($updated_by_label) . '</a>';
    } else {
      echo esc_html($updated_by_label);
    }
    echo ')</span>';
  }
  echo '</div></div>';

  echo '<div><div style="font-size:12px;color:#646970;">Dôvera</div><div><strong>' . (int)$confidence . '%</strong></div></div>';

  echo '<div style="min-width:220px;"><div style="font-size:12px;color:#646970;">Značky</div><div>' . self::render_flags_badges($row['system_flags'] ?? '') . self::render_flags_help($row['system_flags'] ?? '') . '</div></div>';

  echo '</div></div>';


  echo '<form method="post">';
  wp_nonce_field('bc_inv_res_edit_' . $rid);

  echo '<table class="form-table"><tbody>';
  echo '<tr><th scope="row">Pickup</th><td><input type="datetime-local" name="pickup_at" value="' . esc_attr(!empty($row['pickup_at']) ? str_replace(' ', 'T', substr((string)$row['pickup_at'],0,16)) : '') . '" /></td></tr>';

  echo '<tr><th scope="row">Status</th><td><select name="status">';
  foreach (['submitted','confirmed','ready','picked_up','cancelled','expired'] as $st) {
    echo '<option value="' . esc_attr($st) . '"' . selected((string)($row['status'] ?? ''), $st, false) . '>' . esc_html($st) . '</option>';
  }
  echo '</select></td></tr>';

  echo '<tr><th scope="row">Meno</th><td><input type="text" name="customer_name" class="regular-text" value="' . esc_attr((string)($row['customer_name'] ?? '')) . '" /></td></tr>';
  echo '<tr><th scope="row">Email</th><td><input type="email" name="customer_email" class="regular-text" value="' . esc_attr((string)($row['customer_email'] ?? '')) . '" /></td></tr>';
  echo '<tr><th scope="row">Telefón</th><td><input type="text" name="customer_phone" class="regular-text" value="' . esc_attr((string)($row['customer_phone'] ?? '')) . '" /></td></tr>';

  echo '<tr><th scope="row">Poznámka zákazníka</th><td><textarea name="customer_note" rows="3" class="large-text">' . esc_textarea((string)($row['customer_note'] ?? '')) . '</textarea></td></tr>';
  echo '<tr><th scope="row">Interná poznámka</th><td><textarea name="internal_note" rows="3" class="large-text">' . esc_textarea((string)($row['internal_note'] ?? '')) . '</textarea></td></tr>';

  echo '</tbody></table>';

  echo '<h2>Položky</h2>';
  echo '<table class="widefat striped"><thead><tr><th>Názov</th><th>PLU</th><th>WC ID</th><th>Qty</th><th>Cena/ks</th><th>Pozn.</th></tr></thead><tbody>';

  $i = 0;
  foreach ($items as $it) {
    echo '<tr>';
    echo '<td><input type="text" name="item_name[]" value="' . esc_attr((string)($it['name'] ?? '')) . '" style="width:100%;" /></td>';
    echo '<td><input type="text" name="item_plu[]" value="' . esc_attr((string)($it['plu'] ?? '')) . '" style="width:100px;" /></td>';
    echo '<td><input type="number" name="item_wc[]" value="' . esc_attr((string)($it['wc_product_id'] ?? '')) . '" style="width:110px;" /></td>';
    echo '<td><input type="text" name="item_qty[]" value="' . esc_attr((string)($it['qty'] ?? '')) . '" style="width:90px;" /></td>';
    echo '<td><input type="text" name="item_price[]" value="' . esc_attr((string)($it['unit_price'] ?? '')) . '" style="width:90px;" /></td>';
    echo '<td><input type="text" name="item_note[]" value="' . esc_attr((string)($it['note'] ?? '')) . '" style="width:140px;" /></td>';
    echo '</tr>';
    $i++;
  }

  // Extra blank rows for quick add
  for ($k=0; $k<3; $k++) {
    echo '<tr>';
    echo '<td><input type="text" name="item_name[]" value="" style="width:100%;" /></td>';
    echo '<td><input type="text" name="item_plu[]" value="" style="width:100px;" /></td>';
    echo '<td><input type="number" name="item_wc[]" value="" style="width:110px;" /></td>';
    echo '<td><input type="text" name="item_qty[]" value="" style="width:90px;" /></td>';
    echo '<td><input type="text" name="item_price[]" value="" style="width:90px;" /></td>';
    echo '<td><input type="text" name="item_note[]" value="" style="width:140px;" /></td>';
    echo '</tr>';
  }

  echo '</tbody></table>';

  echo '<p style="margin-top:14px;">';
  echo '<input type="hidden" name="bc_inv_edit_res" value="1" />';
  if (!empty($pre_save_warning)) {
    echo '<button class="button" type="submit">Skontrolovať</button> ';
    echo '<button class="button button-primary" type="submit" name="bc_inv_force" value="1">Uložiť aj tak</button>';
  } else {
    submit_button('Uložiť zmeny', 'primary', 'submit', false);
  }
  echo '</p>';

  echo '</form></div>';
}


public static function page_calendar() {
  if (!self::current_user_can_manage()) {
    wp_die('Nemáš oprávnenie.');
  }

  global $wpdb;
  $tRes = self::table('reservations');

  $user_id = get_current_user_id();
  $stores = self::get_accessible_stores_map_for_user($user_id);

  if (empty($stores)) {
    wp_die('Nemáš prístup k žiadnej prevádzke. Priradenie urobíš v menu Inventúra → Prístupy.');
  }

  $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    $has_store_param = isset($_GET['store_id']);
  if ($store_id <= 0) {
    $keys = array_keys($stores);
    $store_id = (int)$keys[0];
  }
  if ($store_id > 0 && !self::current_user_has_store_access($store_id)) {
    wp_die('Nemáš prístup k tejto prevádzke.');
  }

  $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'week'; // week|day|month
  if (!in_array($view, ['week','day','month'], true)) $view = 'week';

  $base_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');

  // Normalize date
  $ts = strtotime($base_date);
  if (!$ts) $ts = time();
  $base_date = date('Y-m-d', $ts);

  // Compute range
  $week_start = $base_date;
  $week_end = $base_date;
  $month_start = $base_date;
  $month_end = $base_date;

  if ($view === 'month') {
    $month_start = date('Y-m-01', $ts);
    $month_end = date('Y-m-t', $ts);
    $range_start = $month_start . ' 00:00:00';
    $range_end = $month_end . ' 23:59:59';
  } elseif ($view === 'week') {
    // Monday-based week
    $dow = (int)date('N', $ts); // 1..7
    $week_start_ts = strtotime('-' . ($dow - 1) . ' days', $ts);
    $week_start = date('Y-m-d', $week_start_ts);
    $week_end_ts = strtotime('+' . (7 - $dow) . ' days', $ts);
    $week_end = date('Y-m-d', $week_end_ts);
    $range_start = $week_start . ' 00:00:00';
    $range_end = $week_end . ' 23:59:59';
  } else { // day
    $range_start = $base_date . ' 00:00:00';
    $range_end = $base_date . ' 23:59:59';
  }


  // Fetch reservations for range (excluding draft)
  if ($store_id > 0) {
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, store_id, status, pickup_at, total_amount, currency, confidence, system_flags, customer_wp_user_id
         FROM $tRes
         WHERE store_id = %d
           AND pickup_at IS NOT NULL
           AND pickup_at BETWEEN %s AND %s
           AND status <> 'draft'
         ORDER BY pickup_at ASC, id ASC",
        $store_id, $range_start, $range_end
      ),
      ARRAY_A
    );
  } else {
    $store_ids = array_keys($stores);
    $in = implode(',', array_fill(0, count($store_ids), '%d'));
    $params = array_merge($store_ids, [$range_start, $range_end]);
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, store_id, status, pickup_at, total_amount, currency, confidence, system_flags, customer_wp_user_id
         FROM $tRes
         WHERE store_id IN ($in)
           AND pickup_at IS NOT NULL
           AND pickup_at BETWEEN %s AND %s
           AND status <> 'draft'
         ORDER BY pickup_at ASC, id ASC",
        $params
      ),
      ARRAY_A
    );
  }

  // Group by day
  $by_day = [];
  foreach ($rows as $r) {
    $d = substr((string)$r['pickup_at'], 0, 10);
    if (!isset($by_day[$d])) $by_day[$d] = [];
    $by_day[$d][] = $r;
  }

  // Helpers
  $store_name = ($store_id === 0) ? 'Všetky prevádzky' : (isset($stores[$store_id]) ? $stores[$store_id] : ('Prevádzka #' . $store_id));

  $base_url = admin_url('admin.php?page=bc-inventura-calendar');
  $q_common = [
    'page' => 'bc-inventura-calendar',
    'store_id' => $store_id,
  ];

  $prev_date = ($view === 'week')
    ? date('Y-m-d', strtotime('-7 days', strtotime($week_start)))
    : (($view === 'month')
        ? date('Y-m-d', strtotime('-1 month', strtotime($month_start)))
        : date('Y-m-d', strtotime('-1 day', strtotime($base_date))));

  $next_date = ($view === 'week')
    ? date('Y-m-d', strtotime('+7 days', strtotime($week_start)))
    : (($view === 'month')
        ? date('Y-m-d', strtotime('+1 month', strtotime($month_start)))
        : date('Y-m-d', strtotime('+1 day', strtotime($base_date))));

  echo '<div class="wrap">';
  echo '<h1>Kalendár rezervácií</h1>';

  echo '<p style="margin-top:6px;">Prevádzka: <strong>' . esc_html($store_name) . '</strong></p>';

  // Filter bar
  echo '<form method="get" style="margin: 10px 0 18px 0; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
  echo '<input type="hidden" name="page" value="bc-inventura-calendar" />';

  echo '<label>Prevádzka&nbsp;';
  echo '<select name="store_id">';
  echo '<option value="0"' . (((int)$store_id===0)?' selected':'') . '>Všetky</option>';
  foreach ($stores as $sid => $sname) {
    $sel = ((int)$sid === (int)$store_id) ? 'selected' : '';
    echo '<option value="' . (int)$sid . '" ' . $sel . '>' . esc_html($sname) . '</option>';
  }
  echo '</select></label>';

  echo '<label>Dátum&nbsp;<input type="date" name="date" value="' . esc_attr($base_date) . '" /></label>';

  echo '<label>Zobrazenie&nbsp;';
  echo '<select name="view">';
  echo '<option value="week"' . ($view==='week'?' selected':'') . '>Týždeň</option>';
  echo '<option value="day"' . ($view==='day'?' selected':'') . '>Deň</option>';
  echo '<option value="month"' . ($view==='month'?' selected':'') . '>Mesiac</option>';
  echo '</select></label>';

  submit_button('Zobraziť', 'primary', 'submit', false);
  echo '</form>';

  // Navigation
  $prev_link = add_query_arg(array_merge($q_common, ['date' => $prev_date, 'view' => $view]), $base_url);
  $next_link = add_query_arg(array_merge($q_common, ['date' => $next_date, 'view' => $view]), $base_url);
  $today_link = add_query_arg(array_merge($q_common, ['date' => current_time('Y-m-d'), 'view' => $view]), $base_url);

  echo '<div style="display:flex; gap:8px; align-items:center; margin-bottom:12px;">';
  echo '<a class="button" href="' . esc_url($prev_link) . '">← Predchádzajúce</a>';
  echo '<a class="button" href="' . esc_url($today_link) . '">Dnes</a>';
  echo '<a class="button" href="' . esc_url($next_link) . '">Nasledujúce →</a>';
  echo '</div>';

  // Legend
  echo '<div style="margin: 6px 0 14px 0; font-size: 12px; opacity: 0.9;">';
  echo '<strong>Legenda:</strong> ';
  echo '<span style="padding:2px 6px; border-radius:10px; background:#fff3cd; border:1px solid #ffeeba; margin-right:6px;">submitted</span>';
  echo '<span style="padding:2px 6px; border-radius:10px; background:#d1ecf1; border:1px solid #bee5eb; margin-right:6px;">confirmed</span>';
  echo '<span style="padding:2px 6px; border-radius:10px; background:#d4edda; border:1px solid #c3e6cb; margin-right:6px;">ready</span>';
  echo '<span style="padding:2px 6px; border-radius:10px; background:#f8d7da; border:1px solid #f5c6cb; margin-right:6px;">cancelled/expired</span>';
  echo '</div>';

  
  // --- Month view (v0.2.15) ---
  if ($view === 'month') {
    // Month boundaries
    $month_start_ts = strtotime($month_start);
    $month_end_ts = strtotime($month_end);

    // Build per-day totals
    $day_totals = []; // Y-m-d => ['count'=>int,'sum'=>float,'currency'=>string]
    foreach ($by_day as $d => $items) {
      $c = 0; $s = 0.0; $cur = 'EUR';
      foreach ($items as $it) {
        $c++;
        $s += (float)($it['total_amount'] ?? 0);
        if (!empty($it['currency'])) $cur = (string)$it['currency'];
      }
      $day_totals[$d] = ['count'=>$c,'sum'=>$s,'currency'=>$cur];
    }

    // Month summary by status
    $m_counts = [];
    $m_sums = [];
    $m_total_count = 0;
    $m_total_sum = 0.0;
    $m_currency = 'EUR';
    foreach ($rows as $rr) {
      $st = !empty($rr['status']) ? (string)$rr['status'] : 'unknown';
      if (!isset($m_counts[$st])) { $m_counts[$st] = 0; $m_sums[$st] = 0.0; }
      $m_counts[$st] += 1;
      $m_sums[$st] += (float)($rr['total_amount'] ?? 0);
      $m_total_count += 1;
      $m_total_sum += (float)($rr['total_amount'] ?? 0);
      if (!empty($rr['currency'])) $m_currency = (string)$rr['currency'];
    }

    echo '<h2 style="margin-top:6px;">Mesiac: <strong>' . esc_html(date('m/Y', $month_start_ts)) . '</strong></h2>';

    echo '<div style="margin:10px 0 12px 0; padding:10px 12px; border:1px solid #ccd0d4; background:#fff; border-radius:8px;">';
    echo '<div><strong>Sumár mesiaca:</strong> ' . (int)$m_total_count . ' | ' . esc_html(number_format((float)$m_total_sum, 2, ',', ' ')) . ' ' . esc_html($m_currency) . '</div>';

    // Status breakdown badges
    $order = ['submitted','confirmed','ready','picked_up','cancelled','expired','unknown'];
    $parts = [];
    foreach ($order as $st) {
      if (!empty($m_counts[$st])) {
        $parts[] = '<span class="bc-badge" style="margin-right:6px;">'
          . esc_html($st) . ': <strong>' . (int)$m_counts[$st] . '</strong>'
          . ' <span style="opacity:0.8;">(' . esc_html(number_format((float)$m_sums[$st], 2, ',', ' ')) . ' ' . esc_html($m_currency) . ')</span>'
          . '</span>';
      }
    }
    foreach ($m_counts as $st => $cnt) {
      if (in_array($st, $order, true)) continue;
      $parts[] = '<span class="bc-badge" style="margin-right:6px;">'
        . esc_html($st) . ': <strong>' . (int)$cnt . '</strong>'
        . ' <span style="opacity:0.8;">(' . esc_html(number_format((float)$m_sums[$st], 2, ',', ' ')) . ' ' . esc_html($m_currency) . ')</span>'
        . '</span>';
    }
    if (!empty($parts)) {
      echo '<div style="margin-top:8px;">' . implode('', $parts) . '</div>';
    }
    echo '</div>';

    // Grid start (Monday) and end (Sunday)
    $grid_start_ts = $month_start_ts;
    $dow = (int)date('N', $grid_start_ts);
    $grid_start_ts = strtotime('-' . ($dow - 1) . ' days', $grid_start_ts);

    $grid_end_ts = $month_end_ts;
    $dow2 = (int)date('N', $grid_end_ts);
    $grid_end_ts = strtotime('+' . (7 - $dow2) . ' days', $grid_end_ts);

    echo '<table class="widefat striped" style="table-layout:fixed;">';
    echo '<thead><tr>';
    foreach (['Po','Ut','St','Št','Pi','So','Ne'] as $h) {
      echo '<th style="text-align:center;">' . esc_html($h) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $cursor = $grid_start_ts;
    while ($cursor <= $grid_end_ts) {
      echo '<tr>';
      for ($i=0; $i<7; $i++) {
        $d = date('Y-m-d', $cursor);
        $in_month = ($cursor >= $month_start_ts && $cursor <= $month_end_ts);
        $style = 'vertical-align:top; height:88px;';
        if (!$in_month) $style .= ' opacity:0.45;';

        $day_url = add_query_arg(array_merge($q_common, ['view'=>'day', 'date'=>$d]), $base_url);
        $label = date('j', $cursor);

        echo '<td style="' . esc_attr($style) . '">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;">';
        echo '<a href="' . esc_url($day_url) . '" style="text-decoration:none;"><strong>' . esc_html($label) . '</strong></a>';
        echo '</div>';

        if (!empty($day_totals[$d]['count'])) {
          $cnt = (int)$day_totals[$d]['count'];
          $sum = (float)$day_totals[$d]['sum'];
          $cur = (string)$day_totals[$d]['currency'];
          echo '<div style="margin-top:6px;">';
          echo '<div><strong>' . $cnt . '</strong> rez.</div>';
          echo '<div>' . esc_html(number_format($sum, 2, ',', ' ')) . ' ' . esc_html($cur) . '</div>';
          echo '</div>';
        }

        echo '</td>';

        $cursor = strtotime('+1 day', $cursor);
      }
      echo '</tr>';
    }
    echo '</tbody></table>';

    echo '</div>'; // wrap
    return;
  }

if ($view === 'day') {
    echo '<h2>' . esc_html($base_date) . '</h2>';
    $day_rows = isset($by_day[$base_date]) ? $by_day[$base_date] : [];
    if (empty($day_rows)) {
      echo '<p>Žiadne rezervácie v tento deň.</p>';
    } else {
      // Day summary
      $day_count = count($day_rows);
      $day_sum = 0.0;
      foreach ($day_rows as $rr) {
        $day_sum += (float)($rr['total_amount'] ?? 0);
      }
      echo '<p style="margin:6px 0 12px 0;"><strong>Sumár dňa:</strong> ' . (int)$day_count . ' rezervácií, spolu <strong>' . esc_html(number_format((float)$day_sum, 2, '.', '')) . ' ' . esc_html($day_rows[0]['currency'] ?? 'EUR') . '</strong></p>';

      // Day breakdown by status
      $day_currency = $day_rows[0]['currency'] ?? 'EUR';
      $d_counts = [];
      $d_sums = [];
      foreach ($day_rows as $rr) {
        $st = !empty($rr['status']) ? (string)$rr['status'] : 'unknown';
        if (!isset($d_counts[$st])) { $d_counts[$st] = 0; $d_sums[$st] = 0.0; }
        $d_counts[$st] += 1;
        $d_sums[$st] += (float)($rr['total_amount'] ?? 0);
      }
      $order = ['submitted','confirmed','ready','picked_up','cancelled','expired','unknown'];
      $parts = [];
      foreach ($order as $st) {
        if (!empty($d_counts[$st])) {
          $parts[] = '<span class="bc-badge" style="margin-right:6px;">'
            . esc_html($st) . ': <strong>' . (int)$d_counts[$st] . '</strong>'
            . ' <span style="opacity:0.8;">(' . esc_html(number_format((float)$d_sums[$st], 2, '.', '')) . ' ' . esc_html($day_currency) . ')</span>'
            . '</span>';
        }
      }
      foreach ($d_counts as $st => $cnt) {
        if (in_array($st, $order, true)) continue;
        $parts[] = '<span class="bc-badge" style="margin-right:6px;">'
          . esc_html($st) . ': <strong>' . (int)$cnt . '</strong>'
          . ' <span style="opacity:0.8;">(' . esc_html(number_format((float)$d_sums[$st], 2, '.', '')) . ' ' . esc_html($day_currency) . ')</span>'
          . '</span>';
      }
      if (!empty($parts)) {
        echo '<div style="margin:0 0 12px 0;">' . implode('', $parts) . '</div>';
      }

      echo '<table class="widefat striped"><thead><tr>';
      echo '<th>Čas</th><th>ID</th><th>Stav</th><th>Suma</th><th>Dôvera</th><th>Značky</th><th>Detail</th>';
      echo '</tr></thead><tbody>';
      foreach ($day_rows as $r) {
        $time = substr((string)$r['pickup_at'], 11, 5);
        $flags = '';
        if (!empty($r['system_flags'])) {
          $flags = is_string($r['system_flags']) ? $r['system_flags'] : '';
        }
        $detail_link = admin_url('admin.php?page=bc-inventura-reservations&store_id=' . (int)$store_id . '&date=' . urlencode($base_date));
        echo '<tr>';
        echo '<td>' . esc_html($time) . '</td>';
        echo '<td>#' . (int)$r['id'] . '</td>';
        echo '<td>' . esc_html($r['status']) . '</td>';
        echo '<td>' . esc_html(number_format((float)$r['total_amount'], 2, '.', '')) . ' ' . esc_html($r['currency']) . '</td>';
        echo '<td>' . (int)$r['confidence'] . '</td>';
        echo '<td>' . self::render_flags_badges($r['system_flags']) . '</td>';
        echo '<td><a href="' . esc_url($detail_link) . '">Otvoriť Rezervácie</a></td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
    return;
  }

  // Week view grid
  echo '<h2>Týždeň: ' . esc_html($week_start) . ' – ' . esc_html($week_end) . '</h2>';

  // Week summary
  $week_count = is_array($rows) ? count($rows) : 0;
  $week_sum = 0.0;
  $week_currency = 'EUR';

  // Status breakdown (counts + sums)
  $st_counts = [];
  $st_sums = [];

  if (!empty($rows)) {
    foreach ($rows as $rr) {
      $amt = (float)($rr['total_amount'] ?? 0);
      $week_sum += $amt;

      $st = !empty($rr['status']) ? (string)$rr['status'] : 'unknown';
      if (!isset($st_counts[$st])) { $st_counts[$st] = 0; $st_sums[$st] = 0.0; }
      $st_counts[$st] += 1;
      $st_sums[$st] += $amt;

      if (!empty($rr['currency'])) {
        $week_currency = (string)$rr['currency'];
      }
    }
  }

  // Render breakdown badges in a stable order
  $order = ['submitted','confirmed','ready','picked_up','cancelled','expired','unknown'];
  $parts = [];
  foreach ($order as $st) {
    if (!empty($st_counts[$st])) {
      $parts[] = '<span class="bc-badge" style="margin-right:6px;">'
        . esc_html($st) . ': <strong>' . (int)$st_counts[$st] . '</strong>'
        . ' <span style="opacity:0.8;">(' . esc_html(number_format((float)$st_sums[$st], 2, '.', '')) . ' ' . esc_html($week_currency) . ')</span>'
        . '</span>';
    }
  }
  // Add any other statuses not in $order
  foreach ($st_counts as $st => $cnt) {
    if (in_array($st, $order, true)) continue;
    $parts[] = '<span class="bc-badge" style="margin-right:6px;">'
      . esc_html($st) . ': <strong>' . (int)$cnt . '</strong>'
      . ' <span style="opacity:0.8;">(' . esc_html(number_format((float)$st_sums[$st], 2, '.', '')) . ' ' . esc_html($week_currency) . ')</span>'
      . '</span>';
  }

  echo '<p style="margin:6px 0 10px 0;"><strong>Sumár týždňa:</strong> ' . (int)$week_count . ' rezervácií, spolu <strong>'
    . esc_html(number_format((float)$week_sum, 2, '.', '')) . ' ' . esc_html($week_currency) . '</strong></p>';

  if (!empty($parts)) {
    echo '<div style="margin:0 0 12px 0;">' . implode('', $parts) . '</div>';
  }


  echo '<style>
    .bc-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;}
    .bc-cal-day{border:1px solid #ccd0d4;background:#fff;border-radius:6px;min-height:140px;display:flex;flex-direction:column;}
    .bc-cal-day h3{margin:0;padding:8px 10px;border-bottom:1px solid #e2e4e7;font-size:13px;background:#f6f7f7;}
    .bc-cal-items{padding:8px 10px;display:flex;flex-direction:column;gap:6px;}
    .bc-cal-item{border:1px solid #e2e4e7;border-left-width:6px;border-radius:6px;padding:6px 8px;}
    .bc-cal-item small{opacity:0.85;}
    .bc-cal-item .meta{display:flex;gap:8px;flex-wrap:wrap;font-size:11px;margin-top:4px;}
    .bc-badge{padding:1px 6px;border-radius:10px;border:1px solid #e2e4e7;background:#fff;font-size:11px;}
    .bc-st-submitted{border-left-color:#856404;background:#fff3cd;}
    .bc-st-confirmed{border-left-color:#0c5460;background:#d1ecf1;}
    .bc-st-ready{border-left-color:#155724;background:#d4edda;}
    .bc-st-cancelled,.bc-st-expired{border-left-color:#721c24;background:#f8d7da;}
  </style>';

  echo '<div class="bc-cal-grid">';

  for ($i=0; $i<7; $i++) {
    $d = date('Y-m-d', strtotime('+' . $i . ' days', strtotime($week_start)));
    $label = date_i18n('D d.m.', strtotime($d));
    $day_link = add_query_arg(array_merge($q_common, ['date' => $d, 'view' => 'day']), $base_url);

    $day_rows = isset($by_day[$d]) ? $by_day[$d] : [];
    $day_count = count($day_rows);
    $day_sum = 0.0;
    foreach ($day_rows as $rr) {
      $day_sum += (float)($rr['total_amount'] ?? 0);
    }

    echo '<div class="bc-cal-day">';
    echo '<h3><a href="' . esc_url($day_link) . '" style="text-decoration:none;">' . esc_html($label) . '</a></h3>';
    echo '<div style="padding:6px 10px; border-bottom:1px solid #e2e4e7; font-size:12px; background:#fff;">'
      . '<span style="opacity:0.85;">Sumár:</span> '
      . '<strong>' . (int)$day_count . '</strong>'
      . ' &nbsp;|&nbsp; '
      . '<strong>' . esc_html(number_format((float)$day_sum, 2, '.', '')) . '</strong> '
      . esc_html($day_count ? ($day_rows[0]['currency'] ?? 'EUR') : 'EUR')
      . '</div>';
    echo '<div class="bc-cal-items">';

    if (empty($day_rows)) {
      echo '<small style="opacity:0.7;">Bez rezervácií</small>';
    } else {
      foreach ($day_rows as $r) {
        $time = substr((string)$r['pickup_at'], 11, 5);
        $st = (string)$r['status'];
        $cls = 'bc-cal-item bc-st-' . esc_attr($st);

        $flags = '';
        $flags = !empty($r['system_flags']) ? (string)$r['system_flags'] : '';

        $res_link = admin_url('admin.php?page=bc-inventura-reservations&store_id=' . (int)$store_id . '&date=' . urlencode($d));
        echo '<div class="' . $cls . '">';
        echo '<div><strong>' . esc_html($time) . '</strong> &nbsp; <a href="' . esc_url($res_link) . '">#' . (int)$r['id'] . '</a></div>';
        echo '<div class="meta">';
        echo '<span class="bc-badge">' . esc_html($st) . '</span>';
        echo '<span class="bc-badge">' . esc_html(number_format((float)$r['total_amount'], 2, '.', '')) . ' ' . esc_html($r['currency']) . '</span>';
        echo '<span class="bc-badge">Dôvera: ' . (int)$r['confidence'] . '</span>';
        if ($flags) echo '<span class="bc-badge">Značky:</span> ' . self::render_flags_badges($flags);
        echo '</div>';
        echo '</div>';
      }
    }

    echo '</div></div>';
  }

  echo '</div>'; // grid
  echo '</div>'; // wrap
}

public static function page_stores() {
    if (!current_user_can('manage_options')) {
      wp_die('Nemáš oprávnenie.');
    }

    global $wpdb;
    $t = self::table('stores');

    // Handle create / update / delete (simple CRUD)
    if (isset($_POST['bc_inv_store_nonce']) && wp_verify_nonce($_POST['bc_inv_store_nonce'], 'bc_inv_store_save')) {
      $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
      $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
      $location = isset($_POST['location']) ? sanitize_text_field($_POST['location']) : '';
      $note = isset($_POST['note']) ? sanitize_textarea_field($_POST['note']) : '';
      $is_active = isset($_POST['is_active']) ? 1 : 0;

      if ($name === '') {
        echo '<div class="notice notice-error"><p>Chýba názov prevádzky.</p></div>';
      } else {
        $data = [
          'name' => $name,
          'location' => ($location !== '' ? $location : null),
          'note' => ($note !== '' ? $note : null),
          'is_active' => $is_active,
        ];

        if ($id > 0) {
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
          $wpdb->update($t, $data, ['id' => $id]);
          echo '<div class="notice notice-success"><p>Prevádzka uložená.</p></div>';
        } else {
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
          $wpdb->insert($t, $data);
          echo '<div class="notice notice-success"><p>Prevádzka vytvorená.</p></div>';
        }
      }
    }

    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
      $id = intval($_GET['id']);
      if ($id > 0 && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'bc_inv_store_delete_' . $id)) {
        // Soft-delete: set inactive (safe for references)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update($t, ['is_active' => 0], ['id' => $id]);
        echo '<div class="notice notice-success"><p>Prevádzka deaktivovaná.</p></div>';
      }
    }

    // Editing?
    $edit_id = (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') ? intval($_GET['id']) : 0;
    $edit = ['id' => 0, 'name' => '', 'location' => '', 'note' => '', 'is_active' => 1];
    if ($edit_id > 0) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
      $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $edit_id), ARRAY_A);
      if (is_array($row)) {
        $edit = [
          'id' => (int)($row['id'] ?? 0),
          'name' => (string)($row['name'] ?? ''),
          'location' => (string)($row['location'] ?? ''),
          'note' => (string)($row['note'] ?? ''),
          'is_active' => (int)($row['is_active'] ?? 1),
        ];
      }
    }

    // List
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY is_active DESC, name ASC", ARRAY_A);

    echo '<div class="wrap"><h1>Prevádzky</h1>';
    echo '<p class="description">Prevádzky majú pevné ID (INT). To je základ pre multiuser hárky, rezervácie a audit.</p>';

    // Form
    echo '<h2>' . ($edit['id'] ? 'Upraviť prevádzku' : 'Pridať prevádzku') . '</h2>';
    echo '<form method="post" style="max-width:760px">';
    wp_nonce_field('bc_inv_store_save', 'bc_inv_store_nonce');
    echo '<input type="hidden" name="id" value="' . esc_attr((int)$edit['id']) . '">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>Názov</label></th><td><input required class="regular-text" name="name" value="' . esc_attr($edit['name']) . '"></td></tr>';
    echo '<tr><th><label>Lokalita</label></th><td><input class="regular-text" name="location" value="' . esc_attr($edit['location']) . '"></td></tr>';
    echo '<tr><th><label>Poznámka</label></th><td><textarea name="note" rows="3" class="large-text">' . esc_textarea($edit['note']) . '</textarea></td></tr>';
    echo '<tr><th><label>Aktívna</label></th><td><label><input type="checkbox" name="is_active" ' . checked(1, (int)$edit['is_active'], false) . '> Áno</label></td></tr>';
    echo '</tbody></table>';
    echo '<p><button class="button button-primary" type="submit">Uložiť</button>';
    if ($edit['id']) {
      echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=bc-inventura-stores')) . '">Nová prevádzka</a>';
    }
    echo '</p>';
    echo '</form>';

    echo '<hr />';
    echo '<h2>Zoznam</h2>';
    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Názov</th><th>Lokalita</th><th>Stav</th><th></th></tr></thead><tbody>';
    if (empty($rows)) {
      echo '<tr><td colspan="5">Zatiaľ žiadne prevádzky. Pridaj prvú vyššie.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $name = (string)($r['name'] ?? '');
        $loc = (string)($r['location'] ?? '');
        $active = (int)($r['is_active'] ?? 0) === 1;
        $edit_url = admin_url('admin.php?page=bc-inventura-stores&action=edit&id=' . $id);
        $del_url = wp_nonce_url(admin_url('admin.php?page=bc-inventura-stores&action=delete&id=' . $id), 'bc_inv_store_delete_' . $id);
        echo '<tr>';
        echo '<td>' . esc_html($id) . '</td>';
        echo '<td>' . esc_html($name) . '</td>';
        echo '<td>' . esc_html($loc) . '</td>';
        echo '<td>' . ($active ? '<span style="color:#0a0">aktívna</span>' : '<span style="color:#999">neaktívna</span>') . '</td>';
        echo '<td>';
        echo '<a class="button" href="' . esc_url($edit_url) . '">Upraviť</a> ';
        echo '<a class="button" href="' . esc_url($del_url) . '" onclick="return confirm(\'Deaktivovať prevádzku?\')">Deaktivovať</a>';
        echo '</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';
    echo '</div>';
  }
  public static function page_store_access() {
    if (!current_user_can('manage_options')) {
      wp_die('Nemáš oprávnenie.');
    }

    global $wpdb;
    $tStores = self::table('stores');
    $tMap = self::table('store_users');

    // Active stores for selection (admins can manage even inactive, but keep UI simple).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $stores = $wpdb->get_results("SELECT id, name, is_active FROM $tStores ORDER BY is_active DESC, name ASC", ARRAY_A);

    $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    $has_store_param = isset($_GET['store_id']);
    if ($store_id <= 0 && !empty($stores)) {
      $store_id = (int)($stores[0]['id'] ?? 0);
    }

    // Handle add user mapping
    if (isset($_POST['bc_inv_access_nonce']) && wp_verify_nonce($_POST['bc_inv_access_nonce'], 'bc_inv_access_save')) {
      $act = isset($_POST['action_kind']) ? sanitize_text_field($_POST['action_kind']) : '';
      $store_id_post = isset($_POST['store_id']) ? (int)$_POST['store_id'] : 0;
      if ($store_id_post > 0) $store_id = $store_id_post;

      if ($act === 'add') {
        $ident = isset($_POST['user_ident']) ? sanitize_text_field($_POST['user_ident']) : '';
        $role = isset($_POST['store_role']) ? sanitize_text_field($_POST['store_role']) : 'seller';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $u = false;
        if ($ident !== '') {
          $u = get_user_by('login', $ident);
          if (!$u) $u = get_user_by('email', $ident);
        }
        if (!$u) {
          echo '<div class="notice notice-error"><p>Nenašiel som užívateľa: <strong>' . esc_html($ident) . '</strong>. Zadaj login alebo e-mail.</p></div>';
        } else {
          $uid = (int)$u->ID;
          // Upsert mapping
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
          $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tMap WHERE store_id=%d AND user_id=%d LIMIT 1", $store_id, $uid));
          $data = [
            'store_id' => $store_id,
            'user_id' => $uid,
            'role_in_store' => $role,
            'is_active' => $is_active,
            'updated_at' => current_time('mysql'),
          ];
          if ($existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update($tMap, $data, ['id' => (int)$existing]);
            if (!empty($wpdb->last_error)) {
              echo '<div class="notice notice-error"><p>DB chyba: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
              echo '<div class="notice notice-success"><p>Prístup aktualizovaný.</p></div>';
            }
          } else {
            $data['created_at'] = current_time('mysql');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($tMap, $data);
            if (!empty($wpdb->last_error)) {
              echo '<div class="notice notice-error"><p>DB chyba: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
              echo '<div class="notice notice-success"><p>Prístup pridaný.</p></div>';
            }
          }
        }
      } elseif ($act === 'update') {
        $map_id = isset($_POST['map_id']) ? (int)$_POST['map_id'] : 0;
        $role = isset($_POST['store_role']) ? sanitize_text_field($_POST['store_role']) : 'seller';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($map_id > 0) {
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
          $wpdb->update($tMap, [
            'role_in_store' => $role,
            'is_active' => $is_active,
            'updated_at' => current_time('mysql'),
          ], ['id' => $map_id]);
          if (!empty($wpdb->last_error)) {
            echo '<div class="notice notice-error"><p>DB chyba: ' . esc_html($wpdb->last_error) . '</p></div>';
          } else {
            echo '<div class="notice notice-success"><p>Uložené.</p></div>';
          }
        }
      }
    }

    // Load mappings for selected store.
    $rows = [];
    if ($store_id > 0) {
      $uTable = $wpdb->users;
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT m.id, m.user_id, m.role_in_store AS store_role, m.is_active, u.user_login, u.display_name, u.user_email
             FROM $tMap m
             LEFT JOIN $uTable u ON u.ID = m.user_id
            WHERE m.store_id=%d
            ORDER BY m.is_active DESC, u.display_name ASC",
          $store_id
        ),
        ARRAY_A
      );
    }

    echo '<div class="wrap"><h1>Prístupy k prevádzkam</h1>';
    echo '<p class="description">Tu priradíš ľudí ku konkrétnej prevádzke (predavač/manager/audítor). Rezervácie a neskôr aj hárky budú rešpektovať tento prístup.</p>';

    // Store selector
    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="bc-inventura-access" />';
    echo '<label><strong>Prevádzka:</strong> <select name="store_id">';
    foreach ($stores as $s) {
      $sid = (int)($s['id'] ?? 0);
      $name = (string)($s['name'] ?? '');
      $active = (int)($s['is_active'] ?? 0) === 1;
      $suffix = $active ? '' : ' (neaktívna)';
      echo '<option value="' . $sid . '"' . selected($store_id, $sid, false) . '>' . esc_html($name . $suffix) . '</option>';
    }
    echo '</select></label> ';
    echo '<button class="button">Zobraziť</button>';
    echo '</form>';

    if ($store_id <= 0) {
      echo '<p>Najprv vytvor prevádzku v menu <strong>Inventúra → Prevádzky</strong>.</p></div>';
      return;
    }

    // Add user form
    echo '<h2>Pridať prístup</h2>';
    echo '<form method="post" style="max-width:900px">';
    wp_nonce_field('bc_inv_access_save', 'bc_inv_access_nonce');
    echo '<input type="hidden" name="action_kind" value="add" />';
    echo '<input type="hidden" name="store_id" value="' . (int)$store_id . '" />';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>Užívateľ (login alebo e-mail)</label></th><td><input required class="regular-text" name="user_ident" placeholder="napr. janko alebo janko@..." /></td></tr>';
    echo '<tr><th><label>Rola v prevádzke</label></th><td>' . self::render_store_role_select('store_role', 'seller') . '</td></tr>';
    echo '<tr><th><label>Aktívny</label></th><td><label><input type="checkbox" name="is_active" checked> Áno</label></td></tr>';
    echo '</tbody></table>';
    echo '<p><button class="button button-primary" type="submit">Pridať / uložiť</button></p>';
    echo '</form>';

    // Current mappings
    echo '<hr />';
    echo '<h2>Aktuálne prístupy</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Užívateľ</th><th>E-mail</th><th>Rola</th><th>Stav</th><th></th></tr></thead><tbody>';
    if (empty($rows)) {
      echo '<tr><td colspan="5">Zatiaľ nikto. Pridaj prvého užívateľa vyššie.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $map_id = (int)($r['id'] ?? 0);
        $disp = (string)($r['display_name'] ?? '');
        $login = (string)($r['user_login'] ?? '');
        $email = (string)($r['user_email'] ?? '');
        $role = (string)($r['store_role'] ?? 'seller');
        $active = (int)($r['is_active'] ?? 0) === 1;

        echo '<tr>';
        echo '<td><strong>' . esc_html($disp ?: $login) . '</strong><div style="color:#666;font-size:12px">' . esc_html($login) . '</div></td>';
        echo '<td>' . esc_html($email) . '</td>';
        echo '<td>';
        echo '<form method="post" style="display:flex;gap:8px;align-items:center">';
        wp_nonce_field('bc_inv_access_save', 'bc_inv_access_nonce');
        echo '<input type="hidden" name="action_kind" value="update" />';
        echo '<input type="hidden" name="store_id" value="' . (int)$store_id . '" />';
        echo '<input type="hidden" name="map_id" value="' . (int)$map_id . '" />';
        echo self::render_store_role_select('store_role', $role);
        echo '</td>';
        echo '<td><label><input type="checkbox" name="is_active" ' . checked(true, $active, false) . '> aktívny</label></td>';
        echo '<td><button class="button" type="submit">Uložiť</button></td>';
        echo '</form>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';
    echo '</div>';
  }
  public static function page_dodaky() {
    if (!self::current_user_can_manage()) {
      wp_die('Nemáš oprávnenie.');
    }

    $store = isset($_GET['store']) ? sanitize_text_field($_GET['store']) : (get_option('bc_inventura_last_store') ?: 'PRIOR BB');
    $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';
    $flagged = isset($_GET['flagged']) ? (int)$_GET['flagged'] : 0;
    $flag = isset($_GET['flag']) ? sanitize_text_field($_GET['flag']) : '';

    $store = self::normalize_store($store);
    $date_norm = self::normalize_date($date) ?: date('Y-m-d');
    update_option('bc_inventura_last_store', $store, false);

    echo '<div class="wrap"><h1>Dodacie listy (fotky)</h1>';
    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="bc-inventura-dodaky" />';
    echo '<label style="margin-right:10px;"><strong>Prevádzka:</strong> <input name="store" value="' . esc_attr($store) . '" class="regular-text" /></label>';
    echo '<label style="margin-right:10px;"><strong>Dátum:</strong> <input name="date" type="date" value="' . esc_attr($date_norm) . '" /></label>';
    echo '<button class="button">Zobraziť</button>';
    echo '</form>';

    $q = new WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => 100,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => '_bc_doc_type', 'value' => 'dodak', 'compare' => '='],
        ['key' => '_bc_store', 'value' => $store, 'compare' => '='],
        ['key' => '_bc_date', 'value' => $date_norm, 'compare' => '='],
      ],
    ]);

    if (!$q->have_posts()) {
      echo '<p>Zatiaľ žiadne dodacie listy pre ' . esc_html($store) . ' – ' . esc_html($date_norm) . '.</p>';
      echo '</div>';
      return;
    }

    echo '<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:12px;">';
    foreach ($q->posts as $att) {
      $time = get_post_meta($att->ID, '_bc_time', true);
      $note = get_post_meta($att->ID, '_bc_note', true);
      $thumb = wp_get_attachment_image($att->ID, 'medium', false, ['style' => 'width:100%;height:auto;border-radius:8px;']);
      $url = wp_get_attachment_url($att->ID);
      echo '<div style="background:#fff; padding:10px; border:1px solid #e5e7eb; border-radius:10px;">';
      echo '<a href="' . esc_url($url) . '" target="_blank">' . $thumb . '</a>';
      echo '<div style="margin-top:8px; font-size:13px;"><strong>' . esc_html($time ?: '') . '</strong></div>';
      if ($note) echo '<div style="font-size:12px; color:#6b7280;">' . esc_html($note) . '</div>';
      echo '</div>';
    }
    echo '</div></div>';
  }

  public static function page_new() {
    if (!self::current_user_can_manage()) {
      wp_die('Nemáš oprávnenie.');
    }

    $stores = self::get_active_stores_map();

    // Very simple placeholder: create an empty sheet post.
    if (isset($_POST['bc_inventura_new_nonce']) && wp_verify_nonce($_POST['bc_inventura_new_nonce'], 'bc_inventura_new')) {
      $store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
      $prevadzka = isset($_POST['prevadzka']) ? sanitize_text_field($_POST['prevadzka']) : 'PRIOR';
      if ($store_id > 0 && isset($stores[$store_id])) {
        $prevadzka = $stores[$store_id];
      }
      $datum = isset($_POST['datum']) ? sanitize_text_field($_POST['datum']) : date('Y-m-d');
      $meno = isset($_POST['meno']) ? sanitize_text_field($_POST['meno']) : '';

      $title = $prevadzka . ' – ' . $datum;
      $post_id = wp_insert_post([
        'post_type' => self::CPT,
        'post_status' => 'publish',
        'post_title' => $title,
      ]);

      update_post_meta($post_id, '_bc_inventura_header', [
        'prevadzka' => $prevadzka,
        'store_id' => ($store_id > 0 ? $store_id : 0),
        'datum' => $datum,
        'meno' => $meno,
        'generated_at' => gmdate('c'),
        'source' => 'wp',
      ]);
      update_post_meta($post_id, '_bc_inventura_rows', []);

      echo '<div class="notice notice-success"><p>Hárok vytvorený. <a target="_blank" href="' . esc_url(admin_url('admin.php?page=bc-inventura-print&id=' . intval($post_id))) . '">Otvoriť tlač</a></p></div>';
    }

    echo '<div class="wrap"><h1>Nový hárok</h1>';
    echo '<form method="post">';
    wp_nonce_field('bc_inventura_new', 'bc_inventura_new_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>Prevádzka</label></th><td>';
    if (!empty($stores)) {
      echo '<select name="store_id">';
      foreach ($stores as $sid => $sname) {
        $sid = (int)$sid;
        echo '<option value="' . esc_attr($sid) . '">' . esc_html($sname) . '</option>';
      }
      echo '</select>';
      echo '<p class="description">Vyber zo zoznamu prevádzok. Ak tu nič nie je, doplň prevádzky v menu <strong>Inventúra → Prevádzky</strong>.</p>';
      echo '<input type="hidden" name="prevadzka" value="" />';
    } else {
      echo '<input name="prevadzka" value="PRIOR" class="regular-text" />';
      echo '<p class="description">Tip: vytvor si prevádzky v menu <strong>Inventúra → Prevádzky</strong> a budeš vyberať z roletky (pevné ID).</p>';
    }
    echo '</td></tr>';
    echo '<tr><th><label>Dátum</label></th><td><input name="datum" type="date" value="' . esc_attr(date('Y-m-d')) . '" /></td></tr>';
    echo '<tr><th><label>Meno</label></th><td><input name="meno" class="regular-text" /></td></tr>';
    echo '</tbody></table>';
    echo '<p><button class="button button-primary" type="submit">Vytvoriť</button></p>';
    echo '<p class="description">Poznámka: plnenie riadkov je primárne cez sync z PWA (woo-search). Táto stránka je len záloha.</p>';
    echo '</form></div>';
  }

  public static function page_print() {
    if (!self::current_user_can_manage()) {
      wp_die('Nemáš oprávnenie.');
    }

    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) wp_die('Chýba ID.');

    $header = get_post_meta($id, '_bc_inventura_header', true);
    $rows = get_post_meta($id, '_bc_inventura_rows', true);
    if (!is_array($header)) $header = [];
    if (!is_array($rows)) $rows = [];

    $prev = $header['prevadzka'] ?? '';
    $datum = $header['datum'] ?? '';
    $meno = $header['meno'] ?? '';

    echo '<div class="bc-inventura-print">';
    echo '<h2>Inventúrne hárky prevádzky</h2>';
    echo '<div class="bc-head">';
    echo '<div><strong>Prevádzka:</strong> ' . esc_html($prev) . '</div>';
    echo '<div><strong>Dátum:</strong> ' . esc_html($datum) . '</div>';
    echo '<div><strong>Meno:</strong> ' . esc_html($meno) . '</div>';
    echo '</div>';

    echo '<table class="bc-table">';
    echo '<thead><tr>';
    echo '<th>Položka</th>';
    echo '<th>Prenos</th>';
    echo '<th>Exp. prenosu</th>';
    echo '<th>Príjem</th>';
    echo '<th>Exp. príjmu</th>';
    echo '<th>Spolu</th>';
    echo '<th>Zostatok</th>';
    echo '<th>Predaj</th>';
    echo '<th>Akcia</th>';
    echo '<th>Odpis</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
      $name = $r['name'] ?? '';
      $prenos = $r['prenos_qty'] ?? 0;
      $prenos_exp = $r['prenos_exp'] ?? '';
      $prijem = $r['prijem_qty'] ?? 0;
      $prijem_exp = $r['prijem_exp'] ?? '';
      $spolu = $r['spolu_qty'] ?? 0;
      $zostatok = $r['zostatok_qty'] ?? 0;
      $predaj = $r['predaj_qty'] ?? 0;
      $akcia_txt = $r['akcia_text'] ?? '';
      $akcia_qty = $r['akcia_qty'] ?? 0;
      $odpis = $r['odpis_qty'] ?? 0;

      // Etapa 4.1 – expiry highlight (use earliest date found in exp fields)
      $cls = self::expiry_class_from_any(trim((string)$prenos_exp . ' ' . (string)$prijem_exp));

      $akcia_cell = trim($akcia_txt);
      if ($akcia_qty) $akcia_cell .= ($akcia_cell ? ' ' : '') . '(' . $akcia_qty . ')';

      echo '<tr class="' . esc_attr($cls) . '">';
      echo '<td>' . esc_html($name) . '</td>';
      echo '<td class="n">' . esc_html($prenos) . '</td>';
      echo '<td>' . esc_html($prenos_exp) . '</td>';
      echo '<td class="n">' . esc_html($prijem) . '</td>';
      echo '<td>' . esc_html($prijem_exp) . '</td>';
      echo '<td class="n">' . esc_html($spolu) . '</td>';
      echo '<td class="n">' . esc_html($zostatok) . '</td>';
      echo '<td class="n">' . esc_html($predaj) . '</td>';
      echo '<td>' . esc_html($akcia_cell) . '</td>';
      echo '<td class="n">' . esc_html($odpis) . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p class="bc-print-actions"><button onclick="window.print()" class="button">Tlačiť / Uložiť do PDF</button></p>';
    echo '</div>';
  }

  // ===== Etapa A1: Nastavenia =====
  public static function page_settings() {
    if (!current_user_can('manage_options')) wp_die('Nemáš oprávnenie.');

    $saved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_inv_settings_nonce']) && wp_verify_nonce(sanitize_text_field($_POST['bc_inv_settings_nonce']), 'bc_inv_settings_save')) {
      $grace = isset($_POST['expiry_grace_hours']) ? (int)$_POST['expiry_grace_hours'] : 2;
      if ($grace < 0) $grace = 0; if ($grace > 72) $grace = 72;
      update_option('bc_inv_expiry_grace_hours', $grace, false);


      $min_pick = isset($_POST['min_pickup_minutes']) ? (int)$_POST['min_pickup_minutes'] : 30;
      if ($min_pick < 0) $min_pick = 0; if ($min_pick > 1440) $min_pick = 1440;
      update_option('bc_inv_min_pickup_minutes', $min_pick, false);


      $cap_max = isset($_POST['capacity_max_per_interval']) ? (int)$_POST['capacity_max_per_interval'] : 0;
      if ($cap_max < 0) $cap_max = 0; if ($cap_max > 500) $cap_max = 500;
      update_option('bc_inv_capacity_max_per_interval', $cap_max, false);


      $roles_raw = isset($_POST['notify_submitted_roles']) ? (string)$_POST['notify_submitted_roles'] : 'manager';
      $roles_raw = preg_replace('/[^a-z,]/', '', strtolower($roles_raw));
      if ($roles_raw === '') $roles_raw = 'manager';
      update_option('bc_inv_notify_submitted_roles', $roles_raw, false);

      $r_limit = isset($_POST['reservations_default_limit']) ? (int)$_POST['reservations_default_limit'] : 100;
      if ($r_limit < 10) $r_limit = 10; if ($r_limit > 500) $r_limit = 500;
      update_option('bc_inv_reservations_default_limit', $r_limit, false);

      $statuses = ['submitted','confirmed','ready','cancelled','expired'];
      foreach ($statuses as $st) {
        $key = 'bc_inv_notify_status_' . $st;
        $on = isset($_POST['notify_status'][$st]) ? '1' : '0';
        update_option($key, $on, false);
      }

      $saved = true;
    }

    $grace = self::get_setting_expiry_grace_hours();
    $min_pick = self::get_setting_min_pickup_minutes();
    $cap_max = self::get_setting_capacity_max_per_interval();
    $roles_sub = implode(',', self::get_setting_notify_submitted_roles());
    $r_limit = self::get_setting_reservations_default_limit();
    $statuses = ['submitted','confirmed','ready','cancelled','expired'];
    $status_on = [];
    foreach ($statuses as $st) {
      $status_on[$st] = self::is_notify_enabled_for_status($st);
    }

    echo '<div class="wrap"><h1>Inventúra – Nastavenia</h1>';
    if ($saved) {
      echo '<div class="notice notice-success"><p>Uložené.</p></div>';
    }

    echo '<form method="post" action="">';
    echo '<input type="hidden" name="bc_inv_settings_nonce" value="' . esc_attr(wp_create_nonce('bc_inv_settings_save')) . '">';

    echo '<table class="form-table" role="presentation">';

    echo '<tr><th scope="row"><label for="expiry_grace_hours">Tolerancia expirácie (hodiny)</label></th>';
    echo '<td><input type="number" min="0" max="72" step="1" id="expiry_grace_hours" name="expiry_grace_hours" value="' . esc_attr((string)$grace) . '" class="small-text">';
    echo '<p class="description">Koľko hodín po čase vyzdvihnutia má systém považovať rezerváciu za platnú, kým ju označí ako expirovanú.</p></td></tr>';

    echo '<tr><th scope="row"><label for="min_pickup_minutes">Minimálny čas vyzdvihnutia (minúty)</label></th>';
    echo '<td><input type="number" min="0" max="1440" step="5" id="min_pickup_minutes" name="min_pickup_minutes" value="' . esc_attr((string)$min_pick) . '" class="small-text">';
    echo '<p class="description">Rezerváciu nie je možné vytvoriť s časom vyzdvihnutia skôr než o tento počet minút od aktuálneho času (pomáha pri plánovaní a znižuje stres).</p></td></tr>';


    echo '<tr><th scope="row"><label for="capacity_max_per_interval">Kapacita – max. rezervácií / 15 min</label></th>';
    echo '<td><input type="number" min="0" max="500" step="1" id="capacity_max_per_interval" name="capacity_max_per_interval" value="' . esc_attr((string)$cap_max) . '" class="small-text">';
    echo '<p class="description">0 = vypnuté. Ak nastavíš &gt; 0, systém pri tvorbe rezervácie nedovolí prekročiť limit v 15-minútovom intervale a navrhne najbližší voľný čas.</p></td></tr>';



    echo '<tr><th scope="row"><label for="notify_submitted_roles">Notifikácia pri „submitted“ – roly</label></th>';
    echo '<td><input type="text" id="notify_submitted_roles" name="notify_submitted_roles" value="' . esc_attr($roles_sub) . '" class="regular-text">';
    echo '<p class="description">Zoznam rolí oddelený čiarkou: <code>manager,seller</code>. Povolené: manager, seller, auditor.</p></td>
</tr>';

    echo '<tr><th scope="row"><label for="reservations_default_limit">Rezervácie – počet záznamov (N)</label></th><td>';
    echo '<input type="number" min="10" max="500" step="10" id="reservations_default_limit" name="reservations_default_limit" value="' . esc_attr($r_limit) . '" />';
    echo '<p class="description">Koľko posledných rezervácií sa má implicitne zobraziť v zozname Rezervácie (bez dátumového filtra).</p></td></tr>';

    echo '<tr><th scope="row">Notifikácie podľa statusu</th><td>';
    foreach ($statuses as $st) {
      $label = ucfirst(str_replace('_', ' ', $st));
      echo '<label style="display:block; margin: 2px 0;"><input type="checkbox" name="notify_status[' . esc_attr($st) . ']" value="1"' . checked($status_on[$st], true, false) . '> ' . esc_html($label) . '</label>';
    }
    echo '<p class="description">Ak vypneš status, emaily sa neodosielajú (ale zmena statusu v systéme normálne prebehne).</p>';
    echo '</td></tr>';

    echo '</table>';

    echo '<p><button type="submit" class="button button-primary">Uložiť</button></p>';
    echo '</form>';
    echo '</div>';
  }

  // ===== Etapa A2: Email log =====
  public static function page_email_log() {
    if (!current_user_can('manage_options')) wp_die('Nemáš oprávnenie.');

    global $wpdb;
    $t = self::table('email_log');
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    echo '<div class="wrap"><h1>Inventúra – Email log</h1>';

    // If the log table doesn't exist yet, show message and stop.
    if ($exists !== $t) {
      echo '<div class="notice notice-warning"><p>Tabuľka email logu ešte neexistuje. Skús deaktivovať/aktivovať plugin alebo otvor admin, aby prebehla DB migrácia.</p></div>';
      echo '</div>';
      return;
    }

    // Handle bulk delete actions
    if (!empty($_POST['bc_inv_email_log_action'])) {
      check_admin_referer('bc_inv_email_log');

      $action = sanitize_text_field((string)$_POST['bc_inv_email_log_action']);
      $store_id_p = isset($_POST['store_id']) ? (int)$_POST['store_id'] : 0;
      $mail_type_p = isset($_POST['mail_type']) ? sanitize_text_field((string)$_POST['mail_type']) : '';
      $ok_p = isset($_POST['ok']) ? sanitize_text_field((string)$_POST['ok']) : '';

      $deleted = 0;

      if ($action === 'delete_selected') {
        $ids = isset($_POST['log_ids']) ? (array)$_POST['log_ids'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) {
          $placeholders = implode(',', array_fill(0, count($ids), '%d'));
          $sqlDel = "DELETE FROM $t WHERE id IN ($placeholders)";
          $deleted = (int) $wpdb->query($wpdb->prepare($sqlDel, $ids));
        }
      } elseif ($action === 'delete_filtered') {
        $whereDel = '1=1';
        $paramsDel = [];
        if ($store_id_p > 0) { $whereDel .= ' AND store_id=%d'; $paramsDel[] = $store_id_p; }
        if ($mail_type_p !== '') { $whereDel .= ' AND mail_type=%s'; $paramsDel[] = $mail_type_p; }
        if ($ok_p === '1' || $ok_p === '0') { $whereDel .= ' AND ok=%d'; $paramsDel[] = (int)$ok_p; }

        $sqlDel = "DELETE FROM $t WHERE $whereDel";
        $sqlDel = $paramsDel ? $wpdb->prepare($sqlDel, $paramsDel) : $sqlDel;
        $deleted = (int) $wpdb->query($sqlDel);
      }

      $redirect = add_query_arg([
        'page' => 'bc-inventura-email-log',
        'store_id' => $store_id_p,
        'mail_type' => $mail_type_p,
        'ok' => $ok_p,
        'deleted' => max(0, (int)$deleted),
      ], admin_url('admin.php'));

      wp_safe_redirect($redirect);
      exit;
    }

    // Flash message after redirect
    if (isset($_GET['deleted'])) {
      $d = (int)$_GET['deleted'];
      if ($d > 0) {
        echo '<div class="notice notice-success"><p>Zmazaných záznamov: <strong>' . esc_html((string)$d) . '</strong></p></div>';
      } else {
        echo '<div class="notice notice-info"><p>Neboli zmazané žiadne záznamy.</p></div>';
      }
    }

    $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    $has_store_param = isset($_GET['store_id']);
    $mail_type = isset($_GET['mail_type']) ? sanitize_text_field((string)$_GET['mail_type']) : '';
    $ok = isset($_GET['ok']) ? sanitize_text_field((string)$_GET['ok']) : '';

    $where = '1=1';
    $params = [];
    if ($store_id > 0) { $where .= ' AND store_id=%d'; $params[] = $store_id; }
    if ($mail_type !== '') { $where .= ' AND mail_type=%s'; $params[] = $mail_type; }
    if ($ok === '1' || $ok === '0') { $where .= ' AND ok=%d'; $params[] = (int)$ok; }

    $sql = "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT 200";
    $sql = $params ? $wpdb->prepare($sql, $params) : $sql;
    $rows = $wpdb->get_results($sql, ARRAY_A);

    // Filters
    $stores = self::get_active_stores();
    echo '<form method="get" action="" style="margin: 10px 0 15px;">';
    echo '<input type="hidden" name="page" value="bc-inventura-email-log">';

    echo '<select name="store_id">';
    echo '<option value="0">Všetky prevádzky</option>';
    foreach ((array)$stores as $sid => $name) {
      $sid = (int)$sid;
      $name = (string)$name;
      if ($sid <= 0) continue;
      echo '<option value="' . esc_attr((string)$sid) . '"' . selected($store_id, $sid, false) . '>' . esc_html($name) . '</option>';
    }
    echo '</select> ';

    echo '<input type="text" name="mail_type" placeholder="typ (submitted/confirmed/...)" value="' . esc_attr($mail_type) . '" style="width:180px;"> ';

    echo '<select name="ok">';
    echo '<option value="">OK aj FAIL</option>';
    echo '<option value="1"' . selected($ok, '1', false) . '>OK</option>';
    echo '<option value="0"' . selected($ok, '0', false) . '>FAIL</option>';
    echo '</select> ';

    echo '<button class="button">Filtrovať</button>';
    echo '</form>';


    // Bulk actions form
    echo '<form method="post" action="">';
    wp_nonce_field('bc_inv_email_log');
    echo '<input type="hidden" name="store_id" value="' . esc_attr((string)$store_id) . '">';
    echo '<input type="hidden" name="mail_type" value="' . esc_attr($mail_type) . '">';
    echo '<input type="hidden" name="ok" value="' . esc_attr($ok) . '">';

    echo '<div style="margin: 10px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
    echo '<select name="bc_inv_email_log_action">';
    echo '<option value="">— Hromadné akcie —</option>';
    echo '<option value="delete_selected">Zmazať označené</option>';
    echo '<option value="delete_filtered">Zmazať všetko podľa filtra</option>';
    echo '</select>';
    echo '<button class="button" onclick="return window.BCInvEmailLogConfirm();">Použiť</button>';
    echo '<span class="description">Tip: najprv si nastav filter, potom môžeš zmazať len tú časť logu.</span>';
    echo '</div>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th class="check-column"><input type="checkbox" id="bc_inv_email_log_check_all"></th><th>Dátum</th><th>Prevádzka</th><th>Rezervácia</th><th>Typ</th><th>Komu</th><th>Predmet</th><th>OK</th><th>Chyba</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
      echo '<tr><td colspan="9">Zatiaľ žiadne záznamy.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $dt = (string)($r['created_at'] ?? '');
        $sid = (int)($r['store_id'] ?? 0);
        $rid = (int)($r['reservation_id'] ?? 0);
        $type = (string)($r['mail_type'] ?? '');
        $to = (string)($r['mail_to'] ?? '');
        $subj = (string)($r['subject'] ?? '');
        $okv = (int)($r['ok'] ?? 0);
        $err = (string)($r['error_text'] ?? '');

        $id = (int)($r['id'] ?? 0);
        $store_name = $sid ? self::get_store_name($sid) : '';
        echo '<tr>';
        echo '<td class="check-column"><input type="checkbox" name="log_ids[]" value="' . esc_attr((string)$id) . '"></td>';
        echo '<td>' . esc_html($dt) . '</td>';
        echo '<td>' . esc_html($store_name) . '</td>';
        echo '<td>' . ($rid ? esc_html('#' . $rid) : '-') . '</td>';
        echo '<td><code>' . esc_html($type) . '</code></td>';
        echo '<td>' . esc_html($to) . '</td>';
        echo '<td>' . esc_html($subj) . '</td>';
        echo '<td>' . ($okv ? 'OK' : '<span style="color:#b32d2e">FAIL</span>') . '</td>';
        echo '<td>' . esc_html($err) . '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
    echo '</form>';

    // JS helpers for Email log bulk actions
    echo '<script>
      window.BCInvEmailLogConfirm = function() {
        var sel = document.querySelector("select[name=bc_inv_email_log_action]");
        if (!sel || !sel.value) { alert("Vyber hromadnú akciu."); return false; }
        if (sel.value === "delete_filtered") {
          return confirm("Naozaj chceš zmazať VŠETKY záznamy podľa aktuálneho filtra?");
        }
        return confirm("Naozaj chceš zmazať označené záznamy?");
      };
      (function(){
        var all = document.getElementById("bc_inv_email_log_check_all");
        if (!all) return;
        all.addEventListener("change", function(){
          document.querySelectorAll("input[name=\'log_ids[]\']").forEach(function(cb){ cb.checked = all.checked; });
        });
      })();
    </script>';
    echo '<p class="description">Zobrazuje sa posledných 200 záznamov (najnovšie hore).</p>';
    echo '</div>';
  }

}
