<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin UI: Audit Log Viewer
 */
trait BC_Inv_Trait_Admin_Audit_UI {

  public static function register_audit_menu() {
    // conservative capability; we can later switch to plugin-specific cap
    if (!current_user_can('manage_options')) return;

    // Put it under the Inventúra CPT menu (edit.php?post_type=...)
    $parent = 'edit.php?post_type=' . self::CPT;
    add_submenu_page(
      $parent,
      'Audit log',
      'Audit log',
      'manage_options',
      'bc-inventura-audit',
      [__CLASS__, 'render_audit_page']
    );
  }

  public static function render_audit_page() {
    if (!current_user_can('manage_options')) {
      wp_die('Access denied');
    }

    global $wpdb;
    $tAudit = self::table('audit');

    // Filters
    $action   = isset($_GET['action_filter']) ? sanitize_key((string)$_GET['action_filter']) : '';
    $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    $user_id  = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $from     = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $to       = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

    $page     = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
    $per_page = 50;
    $offset   = ($page - 1) * $per_page;

    $where = [];
    $args  = [];

    if ($action !== '') {
      $where[] = 'action = %s';
      $args[] = $action;
    }
    if ($store_id > 0) {
      $where[] = 'store_id = %d';
      $args[] = $store_id;
    }
    if ($user_id > 0) {
      $where[] = 'actor_user_id = %d';
      $args[] = $user_id;
    }
    if ($q !== '') {
      $like = '%' . $wpdb->esc_like($q) . '%';
      $where[] = '(payload LIKE %s OR action LIKE %s)';
      array_push($args, $like, $like);
    }
    // Date range on created_at (expects YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
    if ($from !== '') {
      $where[] = 'created_at >= %s';
      $args[] = $from;
    }
    if ($to !== '') {
      $where[] = 'created_at <= %s';
      $args[] = $to;
    }

    $sql_where = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    // Total count for pagination
    $sql_count = "SELECT COUNT(*) FROM $tAudit" . $sql_where;
    if ($args) $sql_count = $wpdb->prepare($sql_count, $args);
    $total = (int) $wpdb->get_var($sql_count);

    // Rows
    $sql = "SELECT * FROM $tAudit" . $sql_where . " ORDER BY id DESC";
    $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);
    if ($args) $sql = $wpdb->prepare($sql, $args);
    $rows = $wpdb->get_results($sql, ARRAY_A);

    // Helpers for preserving filters in pagination links
    $base_args = $_GET;
    unset($base_args['paged']);

    echo '<div class="wrap">';
    echo '<h1>Audit log</h1>';

    // Filter form
    echo '<form method="get" style="margin: 12px 0; padding: 12px; background: #fff; border: 1px solid #dcdcde;">';
    echo '<input type="hidden" name="post_type" value="' . esc_attr(self::CPT) . '"/>';
    echo '<input type="hidden" name="page" value="bc-inventura-audit"/>';

    echo '<label style="margin-right:8px;">Action ';
    echo '<input type="text" name="action_filter" value="' . esc_attr($action) . '" placeholder="lead_create, sheet_edit..."/>';
    echo '</label>';

    echo '<label style="margin-right:8px;">Store ID ';
    echo '<input type="number" name="store_id" value="' . esc_attr($store_id ?: '') . '" style="width:100px;"/>';
    echo '</label>';

    echo '<label style="margin-right:8px;">User ID ';
    echo '<input type="number" name="user_id" value="' . esc_attr($user_id ?: '') . '" style="width:100px;"/>';
    echo '</label>';

    echo '<label style="margin-right:8px;">From ';
    echo '<input type="text" name="from" value="' . esc_attr($from) . '" placeholder="2026-01-31"/>';
    echo '</label>';

    echo '<label style="margin-right:8px;">To ';
    echo '<input type="text" name="to" value="' . esc_attr($to) . '" placeholder="2026-01-31 23:59:59"/>';
    echo '</label>';

    echo '<label style="margin-right:8px;">Search ';
    echo '<input type="text" name="q" value="' . esc_attr($q) . '" placeholder="email, lead_id, ..."/>';
    echo '</label>';

    submit_button('Filter', 'primary', '', false);
    echo ' <a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . self::CPT . '&page=bc-inventura-audit')) . '">Reset</a>';

    echo '</form>';

    // Summary
    echo '<p style="margin: 10px 0;">Total: <strong>' . esc_html($total) . '</strong> entries</p>';

    // Table
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:70px;">ID</th>';
    echo '<th style="width:160px;">Created</th>';
    echo '<th style="width:140px;">Actor</th>';
    echo '<th style="width:160px;">Action</th>';
    echo '<th style="width:90px;">Store</th>';
    echo '<th style="width:110px;">Sheet post</th>';
    echo '<th>Payload</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
      echo '<tr><td colspan="7">No entries found.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $actor_id = (int)($r['actor_user_id'] ?? 0);
        $actor = $actor_id ? get_userdata($actor_id) : null;
        $actor_label = $actor ? ($actor->display_name . ' (#' . $actor_id . ')') : ('#' . $actor_id);

        $payload = (string)($r['payload'] ?? '');
        $payload_short = $payload;
        if (strlen($payload_short) > 220) {
          $payload_short = substr($payload_short, 0, 220) . '…';
        }

        echo '<tr>';
        echo '<td>' . esc_html($r['id']) . '</td>';
        echo '<td>' . esc_html($r['created_at'] ?? '') . '</td>';
        echo '<td>' . esc_html($actor_label) . '</td>';
        echo '<td><code>' . esc_html($r['action'] ?? '') . '</code></td>';
        echo '<td>' . esc_html($r['store_id'] ?? '') . '</td>';
        echo '<td>' . esc_html($r['sheet_post_id'] ?? '') . '</td>';
        echo '<td>';
        echo '<details>';
        echo '<summary style="cursor:pointer;">' . esc_html($payload_short) . '</summary>';
        echo '<pre style="white-space:pre-wrap; margin:8px 0 0; background:#f6f7f7; padding:8px; border:1px solid #dcdcde;">' . esc_html($payload) . '</pre>';
        echo '</details>';
        echo '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    // Pagination
    $total_pages = (int) ceil($total / $per_page);
    if ($total_pages > 1) {
      echo '<div style="margin-top:12px;">';
      $base_url = admin_url('edit.php?post_type=' . self::CPT . '&page=bc-inventura-audit');
      for ($p = 1; $p <= $total_pages; $p++) {
        $url = add_query_arg(array_merge($base_args, ['paged' => $p]), $base_url);
        $style = ($p === $page) ? 'font-weight:bold; text-decoration:underline;' : '';
        echo '<a href="' . esc_url($url) . '" style="margin-right:8px; ' . esc_attr($style) . '">' . esc_html($p) . '</a>';
      }
      echo '</div>';
    }

    echo '</div>';
  }
}
