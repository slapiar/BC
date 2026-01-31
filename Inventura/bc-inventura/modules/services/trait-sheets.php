<?php
if (!defined('ABSPATH')) exit;

trait BC_Inv_Trait_Sheets {

  /**
   * Audit helper for sheets.
   */
  protected static function audit_sheet(string $action, ?int $store_id, ?int $sheet_post_id, array $payload = []) {
    global $wpdb;
    $tAudit = self::table('audit');

    try {
      $wpdb->insert($tAudit, [
        'store_id' => $store_id,
        'sheet_post_id' => $sheet_post_id,
        'actor_user_id' => (int) (function_exists('get_current_user_id') ? get_current_user_id() : 0),
        'action' => sanitize_key($action),
        'payload' => (function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload)),
      ]);
    } catch (\Throwable $e) {
      // best-effort audit only
    }
  }

  public static function ajax_get_nonce() {
    if (!is_user_logged_in() || !self::current_user_can_manage()) {
      wp_send_json_error(['code' => 'not_allowed'], 401);
    }
    wp_send_json_success(['nonce' => wp_create_nonce('bc_inventura_ajax')], 200);
  }

  public static function ajax_post_sheet() {
    if (!is_user_logged_in() || !self::current_user_can_manage()) {
      wp_send_json_error(['code' => 'not_allowed'], 401);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'bc_inventura_ajax')) {
      wp_send_json_error(['code' => 'bad_nonce'], 403);
    }
    $payload_raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) {
      wp_send_json_error(['code' => 'bad_payload'], 400);
    }
    $san = self::sanitize_sheet_payload($payload);
    if (!$san) {
      wp_send_json_error(['code' => 'bad_payload'], 400);
    }

    // Robustnosť: flag "prepísať poslednú" vezmeme priamo z payloadu.
    // (Niektoré klienty môžu poslať boolean/0/1 – !empty to vyrieši.)
    $san['overwrite_last'] = !empty($payload['overwrite_last']);

    $created = self::create_sheet_post($san);
    if (is_wp_error($created)) {
      wp_send_json_error(['code' => 'save_failed', 'message' => $created->get_error_message()], 500);
    }
    $post_id = intval($created);
    $version = (int)get_post_meta($post_id, '_bc_sheet_last_response_version', true);
    delete_post_meta($post_id, '_bc_sheet_last_response_version');
    wp_send_json_success([
      'id' => $post_id,
      'version' => $version,
      'overwritten' => !empty($payload['overwrite_last']),
    ], 200);
  }

  public static function ajax_get_latest() {
    if (!is_user_logged_in() || !self::current_user_can_manage()) {
      wp_send_json_error(['code' => 'not_allowed'], 401);
    }
    $prevadzka = isset($_GET['prevadzka']) ? sanitize_text_field($_GET['prevadzka']) : '';
    $data = self::get_latest_sheet_data($prevadzka);
    if (!$data) {
      wp_send_json_success(['found' => false], 200);
    }
    wp_send_json_success(['found' => true, 'sheet' => $data], 200);
  }
  private static function verify_rest_nonce() {
    $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field($_SERVER['HTTP_X_WP_NONCE']) : '';
    if (!$nonce) return false;
    return (bool) wp_verify_nonce($nonce, 'wp_rest');
  }

  private static function sanitize_sheet_payload($payload) {
    // Expect a JSON object with header + rows.
    if (!is_array($payload)) return null;
    $header = isset($payload['header']) && is_array($payload['header']) ? $payload['header'] : [];
    $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : [];

    $out = [
      'header' => [
        'prevadzka' => isset($header['prevadzka']) ? sanitize_text_field($header['prevadzka']) : '',
        'datum' => isset($header['datum']) ? sanitize_text_field($header['datum']) : '', // YYYY-MM-DD
        'meno' => isset($header['meno']) ? sanitize_text_field($header['meno']) : '',
        'poznamka' => isset($header['poznamka']) ? sanitize_text_field($header['poznamka']) : '',
        'generated_at' => isset($header['generated_at']) ? sanitize_text_field($header['generated_at']) : gmdate('c'),
        'source' => isset($header['source']) ? sanitize_text_field($header['source']) : 'pwa',
      ],
      'rows' => [],
    ];

    foreach ($rows as $r) {
      if (!is_array($r)) continue;
      $out['rows'][] = [
        'product_id' => isset($r['product_id']) ? intval($r['product_id']) : 0,
        'sku' => isset($r['sku']) ? sanitize_text_field($r['sku']) : '',
        'plu' => isset($r['plu']) ? sanitize_text_field($r['plu']) : '',
        'name' => isset($r['name']) ? sanitize_text_field($r['name']) : '',
        'prenos_qty' => isset($r['prenos_qty']) ? floatval($r['prenos_qty']) : 0,
        'prenos_exp' => isset($r['prenos_exp']) ? sanitize_text_field($r['prenos_exp']) : '',
        'prijem_qty' => isset($r['prijem_qty']) ? floatval($r['prijem_qty']) : 0,
        'prijem_exp' => isset($r['prijem_exp']) ? sanitize_text_field($r['prijem_exp']) : '',
        'predaj_qty' => isset($r['predaj_qty']) ? floatval($r['predaj_qty']) : 0,
        'akcia_qty' => isset($r['akcia_qty']) ? floatval($r['akcia_qty']) : 0,
        'akcia_text' => isset($r['akcia_text']) ? sanitize_text_field($r['akcia_text']) : '',
        'odpis_qty' => isset($r['odpis_qty']) ? floatval($r['odpis_qty']) : 0,
        'spolu_qty' => isset($r['spolu_qty']) ? floatval($r['spolu_qty']) : 0,
        'zostatok_qty' => isset($r['zostatok_qty']) ? floatval($r['zostatok_qty']) : 0,
        'alergeny' => isset($r['alergeny']) ? sanitize_text_field($r['alergeny']) : '',
      ];
    }

    // Etapa 1: prepísať poslednú verziu (voliteľné)
    $out['overwrite_last'] = !empty($payload['overwrite_last']);

    return $out;
  }

  private static function create_sheet_post($sheet) {
    if (!is_array($sheet) || !isset($sheet['header']) || !isset($sheet['rows'])) {
      return new WP_Error('bad_sheet', 'Bad sheet');
    }

    $h = $sheet['header'];
    $user = wp_get_current_user();
    $user_id = (int)$user->ID;
    $user_name = $user->display_name ? $user->display_name : $user->user_login;

    $store = !empty($h['prevadzka']) ? self::normalize_store($h['prevadzka']) : 'Prevádzka';
    $date_norm = self::normalize_date($h['datum'] ?? '');
    if (!$date_norm) $date_norm = date('Y-m-d');

    $sheet_key = $store . '|' . $date_norm . '|' . $user_id;
    $overwrite = !empty($sheet['overwrite_last']);
    $last = self::get_last_sheet_by_key($sheet_key);

    // Prepísanie poslednej verzie (bez vytvorenia novej)
    if ($overwrite && $last) {
      $post_id = (int)$last['id'];
      $version = (int)$last['version'];

      update_post_meta($post_id, '_bc_inventura_header', $sheet['header']);
      update_post_meta($post_id, '_bc_inventura_rows', $sheet['rows']);
      update_post_meta($post_id, '_bc_sheet_updated_at', current_time('mysql'));

      wp_update_post([
        'ID' => $post_id,
        'post_title' => sprintf('%s – %s #%d – %s', $store, $date_norm, $version, $user_name),
      ]);

      // pre ajax odpoveď
      update_post_meta($post_id, '_bc_sheet_last_response_version', $version);
      return $post_id;
    }

    // Nová verzia
    $version = $last ? ((int)$last['version'] + 1) : 1;
    $title = sprintf('%s – %s #%d – %s', $store, $date_norm, $version, $user_name);

    $post_id = wp_insert_post([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $user_id,
    ], true);

    if (is_wp_error($post_id)) {
      return $post_id;
    }

    update_post_meta($post_id, '_bc_inventura_header', $sheet['header']);
    update_post_meta($post_id, '_bc_inventura_rows', $sheet['rows']);

    // meta rodiny
    update_post_meta($post_id, '_bc_sheet_store', $store);
    update_post_meta($post_id, '_bc_sheet_date', $date_norm);
    update_post_meta($post_id, '_bc_sheet_user_id', $user_id);
    update_post_meta($post_id, '_bc_sheet_user_name', $user_name);
    update_post_meta($post_id, '_bc_sheet_key', $sheet_key);
    update_post_meta($post_id, '_bc_sheet_version', $version);
    update_post_meta($post_id, '_bc_sheet_created_at', current_time('mysql'));

    // pre ajax odpoveď
    update_post_meta($post_id, '_bc_sheet_last_response_version', $version);
    return $post_id;
  }

  private static function get_latest_sheet_data($prevadzka) {
    $args = [
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'orderby' => 'date',
      'order' => 'DESC',
    ];
    if (!empty($prevadzka)) {
      $args['meta_query'] = [[
        'key' => '_bc_inventura_header',
        'value' => '"prevadzka";s:' . strlen($prevadzka) . ':"' . $prevadzka . '"',
        'compare' => 'LIKE',
      ]];
    }
    $q = new WP_Query($args);
    if (!$q->have_posts()) return null;
    $post_id = $q->posts[0]->ID;
    $header = get_post_meta($post_id, '_bc_inventura_header', true);
    $rows = get_post_meta($post_id, '_bc_inventura_rows', true);
    return [
      'id' => $post_id,
      'header' => is_array($header) ? $header : [],
      'rows' => is_array($rows) ? $rows : [],
    ];
  }

  private static function get_last_sheet_by_key($sheet_key) {
    $posts = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'meta_key' => '_bc_sheet_version',
      'orderby' => 'meta_value_num',
      'order' => 'DESC',
      'meta_query' => [[
        'key' => '_bc_sheet_key',
        'value' => (string)$sheet_key,
        'compare' => '=',
      ]],
      'fields' => 'ids',
    ]);
    if (empty($posts)) return null;
    $id = (int)$posts[0];
    $v = (int)get_post_meta($id, '_bc_sheet_version', true);
    return ['id' => $id, 'version' => $v];
  }

}
