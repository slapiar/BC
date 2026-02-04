<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 1: Admin UI for Chat Access Requests
 * Displays pending access requests queue with approve/deny/wait/expire actions.
 */
trait BC_Inv_Trait_Chat_Admin {

  /**
   * Register admin menu for access requests.
   */
  public static function register_chat_menu() {
    add_submenu_page(
      'bc-inventura',
      'Žiadosti o prístup',
      'Chat → Žiadosti',
      'manage_options',
      'bc-inventura-chat-requests',
      [__CLASS__, 'page_chat_access_requests']
    );
  }

  /**
   * Display access requests admin page.
   */
  public static function page_chat_access_requests() {
    global $wpdb;
    
    // Handle actions
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    $request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    
    if ($action && $request_id && wp_verify_nonce($nonce, 'chat_access_action_' . $request_id)) {
      $admin_user_id = get_current_user_id();
      
      switch ($action) {
        case 'approve':
          $result = self::approve_access_request($request_id, $admin_user_id);
          if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
          } else {
            echo '<div class="notice notice-success"><p>Access request approved successfully. User created and personal room assigned.</p></div>';
          }
          break;
        
        case 'deny':
          $result = self::deny_access_request($request_id, $admin_user_id);
          if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
          } else {
            echo '<div class="notice notice-success"><p>Access request denied.</p></div>';
          }
          break;
        
        case 'wait':
          $result = self::wait_access_request($request_id, $admin_user_id);
          if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
          } else {
            echo '<div class="notice notice-success"><p>Request marked as waiting.</p></div>';
          }
          break;
        
        case 'expire':
          $result = self::expire_access_request($request_id, $admin_user_id);
          if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
          } else {
            echo '<div class="notice notice-success"><p>Request expired.</p></div>';
          }
          break;
      }
    }
    
    // Get access requests
    $tReq = self::table('access_requests');
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
    
    $where = "1=1";
    if ($status_filter && $status_filter !== 'all') {
      $where = $wpdb->prepare("status=%s", $status_filter);
    }
    
    $requests = $wpdb->get_results(
      "SELECT * FROM $tReq WHERE $where ORDER BY created_at DESC LIMIT 100",
      ARRAY_A
    );
    
    ?>
    <div class="wrap">
      <h1>Chat – Žiadosti o prístup</h1>
      
      <p>Správa žiadostí o prístup do chatu. Pending žiadosti čakajú na schválenie adminom.</p>
      
      <ul class="subsubsub">
        <li><a href="?page=bc-inventura-chat-requests&status=pending" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">Pending</a> |</li>
        <li><a href="?page=bc-inventura-chat-requests&status=waiting" class="<?php echo $status_filter === 'waiting' ? 'current' : ''; ?>">Waiting</a> |</li>
        <li><a href="?page=bc-inventura-chat-requests&status=approved" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>">Approved</a> |</li>
        <li><a href="?page=bc-inventura-chat-requests&status=denied" class="<?php echo $status_filter === 'denied' ? 'current' : ''; ?>">Denied</a> |</li>
        <li><a href="?page=bc-inventura-chat-requests&status=expired" class="<?php echo $status_filter === 'expired' ? 'current' : ''; ?>">Expired</a> |</li>
        <li><a href="?page=bc-inventura-chat-requests&status=all" class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">All</a></li>
      </ul>
      
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Čas žiadosti</th>
            <th>Nickname</th>
            <th>Device ID</th>
            <th>Approval Code</th>
            <th>Status</th>
            <th>Expires</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($requests)): ?>
            <tr>
              <td colspan="8" style="text-align:center;padding:30px;">
                Žiadne žiadosti v tejto kategórii.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($requests as $req): ?>
              <?php
                $id = (int)$req['id'];
                $created = esc_html($req['created_at']);
                $nickname = esc_html($req['nickname']);
                $device_id = esc_html($req['device_id']);
                $device_short = esc_html(substr($device_id, 0, 12) . '...');
                $approval_code = esc_html($req['approval_code']);
                $status = esc_html($req['status']);
                $expires = $req['expires_at'] ? esc_html($req['expires_at']) : 'N/A';
                
                // Status badge
                $status_class = 'bc-status-' . $status;
                $status_label = ucfirst($status);
                
                // Check if expired
                $is_expired = false;
                if ($req['expires_at']) {
                  $expires_ts = strtotime($req['expires_at']);
                  $now_ts = current_time('timestamp');
                  $is_expired = $expires_ts < $now_ts;
                }
              ?>
              <tr>
                <td><strong><?php echo $id; ?></strong></td>
                <td><?php echo $created; ?></td>
                <td>
                  <strong><?php echo $nickname; ?></strong>
                  <?php if (!empty($req['phone_hint'])): ?>
                    <br><small>Phone: <?php echo esc_html($req['phone_hint']); ?></small>
                  <?php endif; ?>
                </td>
                <td title="<?php echo $device_id; ?>"><?php echo $device_short; ?></td>
                <td><code style="font-size:16px;font-weight:bold;"><?php echo $approval_code; ?></code></td>
                <td>
                  <span class="<?php echo $status_class; ?>" style="padding:3px 8px;border-radius:3px;font-size:11px;font-weight:bold;">
                    <?php echo $status_label; ?>
                  </span>
                  <?php if ($is_expired && $status === 'pending'): ?>
                    <br><small style="color:#dc3232;">Expired</small>
                  <?php endif; ?>
                </td>
                <td><?php echo $expires; ?></td>
                <td>
                  <?php if ($status === 'pending'): ?>
                    <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'approve', 'request_id' => $id]), 'chat_access_action_' . $id); ?>" 
                       class="button button-primary button-small"
                       onclick="return confirm('Approve access for <?php echo esc_js($nickname); ?>?');">
                      ✅ Approve
                    </a>
                    <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'deny', 'request_id' => $id]), 'chat_access_action_' . $id); ?>" 
                       class="button button-small"
                       onclick="return confirm('Deny access for <?php echo esc_js($nickname); ?>?');">
                      ⛔ Deny
                    </a>
                    <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'wait', 'request_id' => $id]), 'chat_access_action_' . $id); ?>" 
                       class="button button-small">
                      🕒 Wait
                    </a>
                    <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'expire', 'request_id' => $id]), 'chat_access_action_' . $id); ?>" 
                       class="button button-small">
                      🧨 Expire
                    </a>
                  <?php elseif ($status === 'waiting'): ?>
                    <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'approve', 'request_id' => $id]), 'chat_access_action_' . $id); ?>" 
                       class="button button-primary button-small">
                      ✅ Approve
                    </a>
                    <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'deny', 'request_id' => $id]), 'chat_access_action_' . $id); ?>" 
                       class="button button-small">
                      ⛔ Deny
                    </a>
                  <?php else: ?>
                    <span style="color:#888;">No actions</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      
      <style>
        .bc-status-pending { background:#fef8e7; color:#8a6d3b; }
        .bc-status-waiting { background:#d9edf7; color:#31708f; }
        .bc-status-approved { background:#dff0d8; color:#3c763d; }
        .bc-status-denied { background:#f2dede; color:#a94442; }
        .bc-status-expired { background:#f2f2f2; color:#888; }
      </style>
    </div>
    <?php
  }
}
