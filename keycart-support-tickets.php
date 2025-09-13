<?php
/**
 * Plugin Name: KeyCart Support Ticket System
 * Plugin URI: https://keycart.net
 * Description: Complete support ticket system with WooCommerce My Account integration - Login Required
 * Version: 3.0.0
 * Author: Wahba
 * Author URI: https://github.com/Realwahba
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('KCST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KCST_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Create database tables on plugin activation
register_activation_hook(__FILE__, 'kcst_create_database_tables');

function kcst_create_database_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Main tickets table with real_sender_email field
    $tickets_table = $wpdb->prefix . 'keycart_support_tickets';
    $sql1 = "CREATE TABLE $tickets_table (
        id int(11) NOT NULL AUTO_INCREMENT,
        ticket_number varchar(20) NOT NULL,
        name varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        real_sender_email varchar(100),
        user_id int(11),
        order_number varchar(50),
        subject varchar(255) NOT NULL,
        category varchar(50),
        priority varchar(20) DEFAULT 'normal',
        message text NOT NULL,
        status varchar(20) DEFAULT 'new',
        ip_address varchar(45),
        user_agent text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ticket_number (ticket_number)
    ) $charset_collate;";
    
    // Replies table
    $replies_table = $wpdb->prefix . 'keycart_ticket_replies';
    $sql2 = "CREATE TABLE IF NOT EXISTS $replies_table (
        id int(11) NOT NULL AUTO_INCREMENT,
        ticket_id int(11) NOT NULL,
        sender_type varchar(20) NOT NULL,
        sender_name varchar(100) NOT NULL,
        message text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ticket_id (ticket_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    
    // Add options
    add_option('kcst_notification_email', get_option('admin_email'));
    add_option('kcst_email_notifications', 'yes');
    add_option('kcst_last_ticket_number', 0); // For sequential numbering
    add_option('kcst_last_ticket_year', date('Y')); // Track year for reset
    
    // Flush rewrite rules for WooCommerce endpoints
    if (class_exists('WooCommerce')) {
        kcst_add_my_account_endpoint();
        flush_rewrite_rules();
    }
}

// Function to generate next ticket number
function kcst_generate_ticket_number() {
    $current_year = date('Y');
    $last_year = get_option('kcst_last_ticket_year', $current_year);
    
    // Reset counter if new year
    if ($current_year != $last_year) {
        update_option('kcst_last_ticket_year', $current_year);
        update_option('kcst_last_ticket_number', 0);
    }
    
    // Get and increment ticket number
    $last_number = get_option('kcst_last_ticket_number', 0);
    $new_number = $last_number + 1;
    update_option('kcst_last_ticket_number', $new_number);
    
    // Format: KC-YYYY-XXXX (e.g., KC-2025-0001)
    return sprintf('KC-%s-%04d', $current_year, $new_number);
}

// Add admin menu
add_action('admin_menu', 'kcst_add_admin_menu');

function kcst_add_admin_menu() {
    add_menu_page(
        'Support Tickets',
        'Support Tickets',
        'manage_options',
        'keycart-support-tickets',
        'kcst_admin_page',
        'dashicons-tickets-alt',
        30
    );
    
    add_submenu_page(
        'keycart-support-tickets',
        'Settings',
        'Settings',
        'manage_options',
        'keycart-support-settings',
        'kcst_settings_page'
    );
}

// Admin page with reply and edit system
function kcst_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'keycart_support_tickets';
    $replies_table = $wpdb->prefix . 'keycart_ticket_replies';
    
    // Handle ticket edit/update
    if (isset($_POST['update_ticket']) && isset($_POST['ticket_id'])) {
        $ticket_id = intval($_POST['ticket_id']);
        
        $update_data = array(
            'name' => sanitize_text_field($_POST['edit_name']),
            'email' => sanitize_email($_POST['edit_email']),
            'order_number' => sanitize_text_field($_POST['edit_order_number']),
            'subject' => sanitize_text_field($_POST['edit_subject']),
            'category' => sanitize_text_field($_POST['edit_category']),
            'priority' => sanitize_text_field($_POST['edit_priority']),
            'status' => sanitize_text_field($_POST['edit_status']),
            'message' => sanitize_textarea_field($_POST['edit_message'])
        );
        
        $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $ticket_id)
        );
        
        echo '<div class="notice notice-success"><p>Ticket updated successfully!</p></div>';
    }
    
    // Handle status update (existing code)
    if (isset($_POST['update_status']) && isset($_POST['ticket_id']) && !isset($_POST['update_ticket'])) {
        $wpdb->update(
            $table_name,
            array('status' => sanitize_text_field($_POST['new_status'])),
            array('id' => intval($_POST['ticket_id']))
        );
        echo '<div class="notice notice-success"><p>Ticket status updated!</p></div>';
    }
    
    // Handle admin reply
    if (isset($_POST['submit_admin_reply']) && isset($_POST['ticket_id']) && isset($_POST['admin_reply'])) {
        $ticket_id = intval($_POST['ticket_id']);
        $reply_message = sanitize_textarea_field($_POST['admin_reply']);
        
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $ticket_id));
        
        if ($ticket && !empty($reply_message)) {
            $wpdb->insert(
                $replies_table,
                array(
                    'ticket_id' => $ticket_id,
                    'sender_type' => 'admin',
                    'sender_name' => wp_get_current_user()->display_name,
                    'message' => $reply_message,
                    'created_at' => current_time('mysql')
                )
            );
            
            if ($ticket->status === 'new') {
                $wpdb->update(
                    $table_name,
                    array('status' => 'in-progress'),
                    array('id' => $ticket_id)
                );
            }
            
            // Send email to customer (use real email if available, otherwise use provided email)
            $send_to_email = !empty($ticket->real_sender_email) ? $ticket->real_sender_email : $ticket->email;
            
            $subject = 'Reply to Ticket #' . $ticket->ticket_number;
            $message = "Hello " . $ticket->name . ",\n\n";
            $message .= "You have received a reply to your support ticket.\n\n";
            $message .= "Ticket: #" . $ticket->ticket_number . "\n";
            $message .= "Subject: " . $ticket->subject . "\n\n";
            $message .= "Reply from Support Team:\n";
            $message .= "------------------------\n";
            $message .= $reply_message . "\n";
            $message .= "------------------------\n\n";
            
            if (class_exists('WooCommerce')) {
                $message .= "You can view and reply to this ticket in your account:\n";
                $message .= wc_get_page_permalink('myaccount') . "support-tickets/?ticket_id=" . $ticket_id . "\n\n";
            }
            
            $message .= "Best regards,\nKeyCart Support Team";
            
            wp_mail($send_to_email, $subject, $message);
            
            echo '<div class="notice notice-success"><p>Reply sent successfully to: ' . esc_html($send_to_email) . '</p></div>';
        }
    }
    
    // Handle ticket deletion
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['ticket_id'])) {
        $ticket_id = intval($_GET['ticket_id']);
        $wpdb->delete($table_name, array('id' => $ticket_id));
        $wpdb->delete($replies_table, array('ticket_id' => $ticket_id));
        echo '<div class="notice notice-success"><p>Ticket and its replies deleted!</p></div>';
    }
    
    // Get tickets with reply count
    $tickets = $wpdb->get_results("
        SELECT t.*, 
               (SELECT COUNT(*) FROM {$replies_table} WHERE ticket_id = t.id) as reply_count
        FROM {$table_name} t 
        ORDER BY t.created_at DESC
    ");
    
    ?>
    <div class="wrap">
        <h1>Support Tickets</h1>
        
        <style>
            .ticket-table { width: 100%; margin-top: 20px; }
            .ticket-table th { background: #f1f1f1; padding: 10px; text-align: left; }
            .ticket-table td { padding: 10px; border-bottom: 1px solid #ddd; }
            .status-new { color: #d63638; font-weight: bold; }
            .status-in-progress { color: #dba617; font-weight: bold; }
            .status-resolved { color: #00a32a; font-weight: bold; }
            .priority-urgent { color: #d63638; font-weight: bold; }
            .priority-high { color: #d63638; }
            .priority-normal { color: #2271b1; }
            .priority-low { color: #787c82; }
            .ticket-detail-box { padding: 20px; background: #f9f9f9; border-left: 4px solid #007cba; }
            .reply-history { margin: 20px 0; padding: 15px; background: white; border: 1px solid #ddd; border-radius: 5px; }
            .reply-item { margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
            .reply-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
            .reply-header { display: flex; justify-content: space-between; margin-bottom: 10px; }
            .reply-sender { font-weight: bold; color: #333; }
            .reply-date { color: #666; font-size: 12px; }
            .reply-message { color: #555; line-height: 1.5; }
            .admin-reply { background: #fff4e5; padding: 10px; border-radius: 5px; }
            .customer-reply { background: #e8f4fd; padding: 10px; border-radius: 5px; }
            .reply-form { margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 5px; }
            .reply-form textarea { width: 100%; min-height: 100px; padding: 8px; }
            .reply-form .button { margin-top: 10px; }
            .reply-count { display: inline-block; background: #007cba; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 5px; }
            .ticket-stats { display: flex; gap: 20px; margin: 20px 0; }
            .stat-box { background: white; padding: 15px 20px; border: 1px solid #ddd; border-radius: 5px; flex: 1; }
            .stat-number { font-size: 24px; font-weight: bold; color: #007cba; }
            .stat-label { color: #666; font-size: 14px; }
            
            /* Edit form styles */
            .edit-form-container { 
                background: #fff; 
                padding: 20px; 
                border: 2px solid #007cba; 
                border-radius: 5px; 
                margin-top: 20px;
            }
            .edit-form-container h3 { 
                margin-top: 0; 
                color: #007cba;
                border-bottom: 2px solid #e5e7eb;
                padding-bottom: 10px;
            }
            .edit-form-row { 
                display: grid; 
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
                gap: 15px; 
                margin-bottom: 15px; 
            }
            .edit-form-group { display: flex; flex-direction: column; }
            .edit-form-group label { 
                font-weight: bold; 
                margin-bottom: 5px; 
                color: #333; 
            }
            .edit-form-group input, 
            .edit-form-group select, 
            .edit-form-group textarea { 
                padding: 8px; 
                border: 1px solid #ddd; 
                border-radius: 4px; 
            }
            .edit-form-group textarea { 
                min-height: 120px; 
                resize: vertical; 
            }
            .edit-form-buttons {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 1px solid #e5e7eb;
            }
            .button-edit { 
                background: #0073aa; 
                color: white; 
                margin-right: 5px;
            }
            .button-edit:hover { background: #005177; }
            .button-cancel { 
                background: #666; 
                color: white; 
            }
            .button-cancel:hover { background: #555; }
            
            /* Email mismatch warning */
            .email-mismatch {
                background: #fff3cd;
                color: #856404;
                padding: 5px 10px;
                border-radius: 3px;
                font-size: 12px;
                margin-top: 5px;
                display: inline-block;
            }
            .real-sender-info {
                background: #d1ecf1;
                color: #0c5460;
                padding: 10px;
                border-radius: 5px;
                margin: 10px 0;
                border-left: 3px solid #0c5460;
            }
        </style>
        
        <?php
        $total = count($tickets);
        $new_count = count(array_filter($tickets, function($t) { return $t->status === 'new'; }));
        $in_progress = count(array_filter($tickets, function($t) { return $t->status === 'in-progress'; }));
        $resolved = count(array_filter($tickets, function($t) { return $t->status === 'resolved'; }));
        ?>
        
        <div class="ticket-stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo $total; ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color: #d63638;"><?php echo $new_count; ?></div>
                <div class="stat-label">New</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color: #dba617;"><?php echo $in_progress; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color: #00a32a;"><?php echo $resolved; ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>
        
        <table class="ticket-table">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Date</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Real Sender</th>
                    <th>Order #</th>
                    <th>Subject</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Replies</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr><td colspan="12">No tickets found.</td></tr>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td><strong><?php echo esc_html($ticket->ticket_number); ?></strong></td>
                            <td><?php echo esc_html(date('M d, Y H:i', strtotime($ticket->created_at))); ?></td>
                            <td><?php echo esc_html($ticket->name); ?></td>
                            <td>
                                <?php echo esc_html($ticket->email); ?>
                                <?php if (!empty($ticket->real_sender_email) && $ticket->email !== $ticket->real_sender_email): ?>
                                    <br><span class="email-mismatch">⚠️ Typed wrong email</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($ticket->real_sender_email)): ?>
                                    <strong style="color: #0c5460;"><?php echo esc_html($ticket->real_sender_email); ?></strong>
                                    <?php if ($ticket->user_id): ?>
                                        <br><small>User ID: <?php echo $ticket->user_id; ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #999;">Guest</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($ticket->order_number ?? '-'); ?></td>
                            <td><?php echo esc_html($ticket->subject); ?></td>
                            <td><?php echo esc_html($ticket->category); ?></td>
                            <td class="priority-<?php echo esc_attr($ticket->priority); ?>">
                                <?php echo ucfirst(esc_html($ticket->priority)); ?>
                            </td>
                            <td class="status-<?php echo esc_attr($ticket->status); ?>">
                                <?php echo str_replace('-', ' ', ucfirst(esc_html($ticket->status))); ?>
                            </td>
                            <td>
                                <?php if ($ticket->reply_count > 0): ?>
                                    <span class="reply-count"><?php echo $ticket->reply_count; ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="#" onclick="viewTicket(<?php echo $ticket->id; ?>); return false;" class="button button-small">View</a>
                                <a href="#" onclick="editTicket(<?php echo $ticket->id; ?>); return false;" class="button button-small button-edit">Edit</a>
                                <a href="?page=keycart-support-tickets&action=delete&ticket_id=<?php echo $ticket->id; ?>" 
                                   onclick="return confirm('Are you sure? This will delete the ticket and all replies.');" class="button button-small">Delete</a>
                            </td>
                        </tr>
                        
                        <!-- Edit Form Row (hidden by default) -->
                        <tr id="ticket-edit-<?php echo $ticket->id; ?>" style="display:none;">
                            <td colspan="12">
                                <div class="edit-form-container">
                                    <h3>✏️ Edit Ticket #<?php echo esc_html($ticket->ticket_number); ?></h3>
                                    
                                    <?php if (!empty($ticket->real_sender_email) && $ticket->email !== $ticket->real_sender_email): ?>
                                    <div class="real-sender-info">
                                        <strong>⚠️ Important:</strong> The customer is logged in as <strong><?php echo esc_html($ticket->real_sender_email); ?></strong> 
                                        but entered <strong><?php echo esc_html($ticket->email); ?></strong> in the form. 
                                        Consider updating the email field to match their real account email for proper communication.
                                    </div>
                                    <?php endif; ?>
                                    
                                    <form method="post">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket->id; ?>">
                                        
                                        <div class="edit-form-row">
                                            <div class="edit-form-group">
                                                <label>Name:</label>
                                                <input type="text" name="edit_name" value="<?php echo esc_attr($ticket->name); ?>" required>
                                            </div>
                                            <div class="edit-form-group">
                                                <label>Email: <span style="color: #d63638;">*Important*</span></label>
                                                <input type="email" name="edit_email" value="<?php echo esc_attr($ticket->email); ?>" required>
                                                <?php if (!empty($ticket->real_sender_email)): ?>
                                                    <small style="color: #0c5460;">Real sender: <?php echo esc_html($ticket->real_sender_email); ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="edit-form-group">
                                                <label>Order Number:</label>
                                                <input type="text" name="edit_order_number" value="<?php echo esc_attr($ticket->order_number); ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="edit-form-row">
                                            <div class="edit-form-group">
                                                <label>Subject:</label>
                                                <input type="text" name="edit_subject" value="<?php echo esc_attr($ticket->subject); ?>" required>
                                            </div>
                                            <div class="edit-form-group">
                                                <label>Category:</label>
                                                <select name="edit_category">
                                                    <option value="">Select Category</option>
                                                    <option value="technical" <?php selected($ticket->category, 'technical'); ?>>Technical Issue</option>
                                                    <option value="billing" <?php selected($ticket->category, 'billing'); ?>>Billing</option>
                                                    <option value="general" <?php selected($ticket->category, 'general'); ?>>General Inquiry</option>
                                                    <option value="feature" <?php selected($ticket->category, 'feature'); ?>>Feature Request</option>
                                                    <option value="activation" <?php selected($ticket->category, 'activation'); ?>>Product Activation</option>
                                                    <option value="payment" <?php selected($ticket->category, 'payment'); ?>>Payment Problem</option>
                                                    <option value="delivery" <?php selected($ticket->category, 'delivery'); ?>>License Not Received</option>
                                                    <option value="refund" <?php selected($ticket->category, 'refund'); ?>>Refund Request</option>
                                                    <option value="account" <?php selected($ticket->category, 'account'); ?>>Account Issue</option>
                                                    <option value="other" <?php selected($ticket->category, 'other'); ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="edit-form-row">
                                            <div class="edit-form-group">
                                                <label>Priority:</label>
                                                <select name="edit_priority">
                                                    <option value="low" <?php selected($ticket->priority, 'low'); ?>>Low</option>
                                                    <option value="normal" <?php selected($ticket->priority, 'normal'); ?>>Normal</option>
                                                    <option value="high" <?php selected($ticket->priority, 'high'); ?>>High</option>
                                                    <option value="urgent" <?php selected($ticket->priority, 'urgent'); ?>>Urgent</option>
                                                </select>
                                            </div>
                                            <div class="edit-form-group">
                                                <label>Status:</label>
                                                <select name="edit_status">
                                                    <option value="new" <?php selected($ticket->status, 'new'); ?>>New</option>
                                                    <option value="in-progress" <?php selected($ticket->status, 'in-progress'); ?>>In Progress</option>
                                                    <option value="resolved" <?php selected($ticket->status, 'resolved'); ?>>Resolved</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="edit-form-group">
                                            <label>Original Message:</label>
                                            <textarea name="edit_message" required><?php echo esc_textarea($ticket->message); ?></textarea>
                                        </div>
                                        
                                        <div class="edit-form-buttons">
                                            <input type="submit" name="update_ticket" value="Save Changes" class="button button-primary">
                                            <button type="button" onclick="cancelEdit(<?php echo $ticket->id; ?>);" class="button button-cancel">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        
                        <!-- View Details Row (existing) -->
                        <tr id="ticket-details-<?php echo $ticket->id; ?>" style="display:none;">
                            <td colspan="12">
                                <div class="ticket-detail-box">
                                    <?php if (!empty($ticket->real_sender_email) && $ticket->email !== $ticket->real_sender_email): ?>
                                    <div class="real-sender-info">
                                        <strong>⚠️ Email Mismatch Detected:</strong><br>
                                        Customer Account Email: <strong><?php echo esc_html($ticket->real_sender_email); ?></strong><br>
                                        Email Entered in Form: <strong><?php echo esc_html($ticket->email); ?></strong><br>
                                        All replies will be sent to: <strong><?php echo esc_html($ticket->real_sender_email); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <h3>Original Message:</h3>
                                    <p><?php echo nl2br(esc_html($ticket->message)); ?></p>
                                    
                                    <?php if (!empty($ticket->order_number)): ?>
                                    <p><strong>Order Number:</strong> <?php echo esc_html($ticket->order_number); ?></p>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $replies = $wpdb->get_results($wpdb->prepare(
                                        "SELECT * FROM {$replies_table} WHERE ticket_id = %d ORDER BY created_at ASC",
                                        $ticket->id
                                    ));
                                    
                                    if (!empty($replies)):
                                    ?>
                                    <div class="reply-history">
                                        <h3>Conversation History:</h3>
                                        <?php foreach ($replies as $reply): ?>
                                            <div class="reply-item <?php echo $reply->sender_type === 'admin' ? 'admin-reply' : 'customer-reply'; ?>">
                                                <div class="reply-header">
                                                    <span class="reply-sender">
                                                        <?php 
                                                        if ($reply->sender_type === 'admin') {
                                                            echo '👨‍💼 ' . esc_html($reply->sender_name) . ' (Support)';
                                                        } else {
                                                            echo '👤 ' . esc_html($ticket->name) . ' (Customer)';
                                                        }
                                                        ?>
                                                    </span>
                                                    <span class="reply-date"><?php echo date('M d, Y at H:i', strtotime($reply->created_at)); ?></span>
                                                </div>
                                                <div class="reply-message">
                                                    <?php echo nl2br(esc_html($reply->message)); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="reply-form">
                                        <h3>Send Reply:</h3>
                                        <form method="post">
                                            <input type="hidden" name="ticket_id" value="<?php echo $ticket->id; ?>">
                                            <textarea name="admin_reply" placeholder="Type your reply here..." required></textarea>
                                            <br>
                                            <input type="submit" name="submit_admin_reply" value="Send Reply" class="button button-primary">
                                            <?php if (!empty($ticket->real_sender_email)): ?>
                                                <small style="margin-left: 10px; color: #0c5460;">
                                                    Reply will be sent to: <strong><?php echo esc_html($ticket->real_sender_email); ?></strong>
                                                </small>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                    
                                    <form method="post" style="margin-top: 20px;">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket->id; ?>">
                                        <label>Update Status: </label>
                                        <select name="new_status">
                                            <option value="new" <?php selected($ticket->status, 'new'); ?>>New</option>
                                            <option value="in-progress" <?php selected($ticket->status, 'in-progress'); ?>>In Progress</option>
                                            <option value="resolved" <?php selected($ticket->status, 'resolved'); ?>>Resolved</option>
                                        </select>
                                        <input type="submit" name="update_status" value="Update Status" class="button">
                                    </form>
                                    
                                    <div style="margin-top: 10px; color: #666; font-size: 12px;">
                                        IP: <?php echo esc_html($ticket->ip_address); ?><br>
                                        User Agent: <?php echo esc_html($ticket->user_agent); ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div style="margin-top: 20px;">
            <a href="?page=keycart-support-tickets&export_tickets=1" class="button">Export to CSV</a>
        </div>
        
        <script>
            function viewTicket(id) {
                // Close all other sections
                closeAllSections();
                
                var details = document.getElementById('ticket-details-' + id);
                if (details.style.display === 'none') {
                    details.style.display = 'table-row';
                } else {
                    details.style.display = 'none';
                }
            }
            
            function editTicket(id) {
                // Close all other sections
                closeAllSections();
                
                var editForm = document.getElementById('ticket-edit-' + id);
                if (editForm.style.display === 'none') {
                    editForm.style.display = 'table-row';
                } else {
                    editForm.style.display = 'none';
                }
            }
            
            function cancelEdit(id) {
                document.getElementById('ticket-edit-' + id).style.display = 'none';
            }
            
            function closeAllSections() {
                // Close all detail views
                var allDetails = document.querySelectorAll('[id^="ticket-details-"]');
                allDetails.forEach(function(el) {
                    el.style.display = 'none';
                });
                
                // Close all edit forms
                var allEditForms = document.querySelectorAll('[id^="ticket-edit-"]');
                allEditForms.forEach(function(el) {
                    el.style.display = 'none';
                });
            }
        </script>
    </div>
    <?php
}

// Settings page
function kcst_settings_page() {
    if (isset($_POST['save_settings'])) {
        update_option('kcst_notification_email', sanitize_email($_POST['notification_email']));
        update_option('kcst_email_notifications', sanitize_text_field($_POST['email_notifications']));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $notification_email = get_option('kcst_notification_email');
    $email_notifications = get_option('kcst_email_notifications');
    
    ?>
    <div class="wrap">
        <h1>Support Ticket Settings</h1>
        
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">Notification Email</th>
                    <td>
                        <input type="email" name="notification_email" value="<?php echo esc_attr($notification_email); ?>" class="regular-text">
                        <p class="description">Email address to receive new ticket notifications.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Email Notifications</th>
                    <td>
                        <label>
                            <input type="radio" name="email_notifications" value="yes" <?php checked($email_notifications, 'yes'); ?>>
                            Enabled
                        </label>
                        <label style="margin-left: 20px;">
                            <input type="radio" name="email_notifications" value="no" <?php checked($email_notifications, 'no'); ?>>
                            Disabled
                        </label>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="save_settings" class="button-primary" value="Save Settings">
            </p>
        </form>
        
        <h2>Shortcode Usage</h2>
        <p>Use this shortcode to display the support ticket form on any page or post:</p>
        <code>[keycart_support_form]</code>
        <p class="description"><strong>Note:</strong> Users must be logged in to submit tickets.</p>
        
        <h2>AJAX Endpoint</h2>
        <p>For custom forms, submit to this endpoint:</p>
        <code><?php echo admin_url('admin-ajax.php?action=kcst_submit_ticket'); ?></code>
    </div>
    <?php
}

// Register shortcode for the form
add_shortcode('keycart_support_form', 'kcst_support_form_shortcode');

function kcst_support_form_shortcode() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        ob_start();
        ?>
        <div id="kcst-login-required">
            <style>
                .kcst-login-box {
                    background: #f9fafb;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    padding: 30px;
                    text-align: center;
                    margin: 20px 0;
                }
                .kcst-login-box h3 {
                    color: #1f2937;
                    margin-bottom: 15px;
                }
                .kcst-login-box p {
                    color: #6b7280;
                    margin-bottom: 20px;
                }
                .kcst-login-button {
                    background: #007cba;
                    color: white;
                    padding: 12px 30px;
                    text-decoration: none;
                    border-radius: 6px;
                    display: inline-block;
                    font-weight: 600;
                    transition: all 0.3s;
                    cursor: pointer;
                    border: none;
                }
                .kcst-login-button:hover {
                    background: #005a87;
                    color: white;
                    transform: translateY(-2px);
                }
                .kcst-register-link {
                    color: #007cba;
                    text-decoration: none;
                    font-weight: 600;
                    margin-left: 10px;
                }
                .kcst-register-link:hover {
                    text-decoration: underline;
                }
            </style>
            <div class="kcst-login-box">
                <h3>🔐 Login Required</h3>
                <p>You must be logged in to submit a support ticket. This helps us provide better support and track your requests.</p>
                
                <?php if (class_exists('WooCommerce')): ?>
                    <?php 
                    // Check if there's a modal trigger for login (common class names)
                    $possible_modal_triggers = array(
                        'login-modal-trigger',
                        'account-modal-link',
                        'login-popup',
                        'header-login',
                        'xoo-el-login-tgr', // Common WooCommerce login popup plugin
                        'woocommerce-login-popup',
                        'wooc-login-popup'
                    );
                    ?>
                    <button onclick="kcst_trigger_login_modal()" class="kcst-login-button">Login to Continue</button>
                    
                    <script>
                    function kcst_trigger_login_modal() {
                        // Try to find and click the existing login modal trigger
                        var triggers = [
                            '.login-modal-trigger',
                            '.account-modal-link', 
                            '.login-popup',
                            '.header-login',
                            '.xoo-el-login-tgr',
                            '.woocommerce-login-popup',
                            '.wooc-login-popup',
                            'a[href*="my-account"]:contains("Login")',
                            'a[href*="my-account"]:contains("login")',
                            '.header a[href*="login"]',
                            '.header a[href*="my-account"]'
                        ];
                        
                        var found = false;
                        for (var i = 0; i < triggers.length; i++) {
                            var elements = jQuery(triggers[i]);
                            if (elements.length > 0) {
                                elements.first().click();
                                found = true;
                                break;
                            }
                        }
                        
                        // If no modal trigger found, redirect to WooCommerce my-account page
                        if (!found) {
                            <?php if (function_exists('wc_get_page_permalink')): ?>
                                window.location.href = '<?php echo esc_js(wc_get_page_permalink('myaccount')); ?>';
                            <?php else: ?>
                                window.location.href = '<?php echo esc_js(wp_login_url(get_permalink())); ?>';
                            <?php endif; ?>
                        }
                    }
                    
                    // Also try to detect if there's a custom event to trigger the modal
                    jQuery(document).ready(function($) {
                        // Some themes/plugins use custom events
                        $('.kcst-login-button').on('click', function(e) {
                            // Try triggering common custom events
                            $(document).trigger('open-login-modal');
                            $(document).trigger('woo-login-popup-open');
                            $('body').trigger('xoo-el-login-popup');
                        });
                    });
                    </script>
                    
                    <?php if (get_option('users_can_register')): ?>
                        <p style="margin-top: 15px;">
                            Don't have an account? 
                            <a href="#" onclick="kcst_trigger_register_modal(); return false;" class="kcst-register-link">Register here</a>
                        </p>
                        
                        <script>
                        function kcst_trigger_register_modal() {
                            // Try to trigger register tab if modal is already open
                            jQuery('.register-tab, .tab-register, a[href="#register"], a[data-tab="register"]').click();
                            
                            // Otherwise trigger login modal first
                            kcst_trigger_login_modal();
                            
                            // Then switch to register tab after a short delay
                            setTimeout(function() {
                                jQuery('.register-tab, .tab-register, a[href="#register"], a[data-tab="register"]').click();
                            }, 500);
                        }
                        </script>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Fallback if WooCommerce is not active -->
                    <a href="<?php echo wp_login_url(get_permalink()); ?>" class="kcst-login-button">Login to Continue</a>
                    <?php if (get_option('users_can_register')): ?>
                        <p style="margin-top: 15px;">
                            Don't have an account? 
                            <a href="<?php echo wp_registration_url(); ?>">Register here</a>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // Get current user info
    $current_user = wp_get_current_user();
    
    ob_start();
    ?>
    <div id="kcst-support-form">
        <form id="keycart-ticket-form" method="post">
            <?php wp_nonce_field('kcst_submit_ticket', 'kcst_nonce'); ?>
            
            <div class="form-group">
                <label for="kcst_name">Name *</label>
                <input type="text" id="kcst_name" name="name" value="<?php echo esc_attr($current_user->display_name); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="kcst_email">Email *</label>
                <input type="email" id="kcst_email" name="email" value="<?php echo esc_attr($current_user->user_email); ?>" required>
                <small style="color: #666;">Your ticket replies will be sent to this email address</small>
            </div>
            
            <div class="form-group">
                <label for="kcst_order_number">Order Number</label>
                <input type="text" id="kcst_order_number" name="order_number" placeholder="Optional - e.g., KC-123456">
            </div>
            
            <div class="form-group">
                <label for="kcst_subject">Subject *</label>
                <input type="text" id="kcst_subject" name="subject" required>
            </div>
            
            <div class="form-group">
                <label for="kcst_category">Category</label>
                <select id="kcst_category" name="category">
                    <option value="">Select Category</option>
                    <option value="technical">Technical Issue</option>
                    <option value="billing">Billing</option>
                    <option value="general">General Inquiry</option>
                    <option value="feature">Feature Request</option>
                    <option value="activation">Product Activation</option>
                    <option value="payment">Payment Problem</option>
                    <option value="delivery">License Not Received</option>
                    <option value="refund">Refund Request</option>
                    <option value="account">Account Issue</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="kcst_priority">Priority</label>
                <select id="kcst_priority" name="priority">
                    <option value="low">Low</option>
                    <option value="normal" selected>Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="kcst_message">Message *</label>
                <textarea id="kcst_message" name="message" rows="6" required></textarea>
            </div>
            
            <button type="submit">Submit Ticket</button>
            

        </form>
        
        <div id="kcst-message" style="display:none; margin-top: 20px;"></div>
    </div>
    
    <style>
        #kcst-support-form .form-group {
            margin-bottom: 20px;
        }
        #kcst-support-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        #kcst-support-form input,
        #kcst-support-form select,
        #kcst-support-form textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        #kcst-support-form button {
            background: #0073aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        #kcst-support-form button:hover {
            background: #005177;
        }
        .kcst-success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
        }
        .kcst-error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        $('#keycart-ticket-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            formData += '&action=kcst_submit_ticket';
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $('#kcst-message').html('<div class="kcst-success">' + response.data.message + '</div>').show();
                        $('#keycart-ticket-form')[0].reset();
                        
                        // Option 1: Re-populate user info after reset (if keeping auto-fill)
                        $('#kcst_name').val('<?php echo esc_js($current_user->display_name); ?>');
                        $('#kcst_email').val('<?php echo esc_js($current_user->user_email); ?>');
                        
                        // Option 2: Don't re-populate (uncomment below and comment above if removing auto-fill)
                        // $('#kcst_name').val('');
                        // $('#kcst_email').val('');
                    } else {
                        $('#kcst-message').html('<div class="kcst-error">' + response.data.message + '</div>').show();
                    }
                },
                error: function() {
                    $('#kcst-message').html('<div class="kcst-error">An error occurred. Please try again.</div>').show();
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// Handle AJAX form submission
add_action('wp_ajax_kcst_submit_ticket', 'kcst_handle_ticket_submission');
add_action('wp_ajax_nopriv_kcst_submit_ticket', 'kcst_handle_ticket_submission');

function kcst_handle_ticket_submission() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'You must be logged in to submit a ticket.'));
    }
    
    if (!isset($_POST['kcst_nonce']) || !wp_verify_nonce($_POST['kcst_nonce'], 'kcst_submit_ticket')) {
        wp_send_json_error(array('message' => 'Security verification failed.'));
    }
    
    $required_fields = ['name', 'email', 'subject', 'message'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }
    }
    
    // Get current user info
    $current_user = wp_get_current_user();
    $real_sender_email = $current_user->user_email;
    $user_id = $current_user->ID;
    
    $name = sanitize_text_field($_POST['name']);
    $email = sanitize_email($_POST['email']);
    $subject = sanitize_text_field($_POST['subject']);
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $priority = isset($_POST['priority']) ? sanitize_text_field($_POST['priority']) : 'normal';
    $message = sanitize_textarea_field($_POST['message']);
    $order_number = isset($_POST['order_number']) ? sanitize_text_field($_POST['order_number']) : '';
    
    // Generate new ticket number format
    $ticket_number = kcst_generate_ticket_number();
    
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'keycart_support_tickets';
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'ticket_number' => $ticket_number,
            'name' => $name,
            'email' => $email,
            'real_sender_email' => $real_sender_email,
            'user_id' => $user_id,
            'order_number' => $order_number,
            'subject' => $subject,
            'category' => $category,
            'priority' => $priority,
            'message' => $message,
            'status' => 'new',
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        )
    );
    
    if ($result === false) {
        wp_send_json_error(array('message' => 'Failed to submit ticket. Please try again.'));
    }
    
    if (get_option('kcst_email_notifications') === 'yes') {
        $to = get_option('kcst_notification_email');
        $email_subject = 'New Support Ticket: ' . $subject;
        $email_body = "A new support ticket has been submitted.\n\n";
        $email_body .= "Ticket Number: $ticket_number\n";
        $email_body .= "Name: $name\n";
        $email_body .= "Email (entered): $email\n";
        $email_body .= "Real Sender Email: $real_sender_email\n";
        if (!empty($order_number)) {
            $email_body .= "Order Number: $order_number\n";
        }
        $email_body .= "Subject: $subject\n";
        $email_body .= "Category: $category\n";
        $email_body .= "Priority: $priority\n\n";
        $email_body .= "Message:\n$message\n\n";
        $email_body .= "View in admin: " . admin_url('admin.php?page=keycart-support-tickets');
        
        wp_mail($to, $email_subject, $email_body);
    }
    
    // Send confirmation to real sender email
    $customer_subject = 'Ticket Received: ' . $ticket_number;
    $customer_body = "Dear $name,\n\n";
    $customer_body .= "Thank you for contacting us. We have received your support ticket.\n\n";
    $customer_body .= "Ticket Number: $ticket_number\n";
    $customer_body .= "Subject: $subject\n\n";
    $customer_body .= "We will review your request and respond as soon as possible.\n\n";
    
    if (class_exists('WooCommerce')) {
        $customer_body .= "You can track your ticket status in your account:\n";
        $customer_body .= wc_get_page_permalink('myaccount') . "support-tickets/\n\n";
    }
    
    $customer_body .= "Best regards,\nKeyCart Support Team";
    
    // Send to real sender email for better delivery
    wp_mail($real_sender_email, $customer_subject, $customer_body);
    
    wp_send_json_success(array(
        'message' => 'Your ticket has been submitted successfully. Ticket number: ' . $ticket_number . '. A confirmation has been sent to ' . $real_sender_email,
        'ticket_number' => $ticket_number
    ));
}

// Export tickets functionality
add_action('admin_init', 'kcst_export_tickets');

function kcst_export_tickets() {
    if (isset($_GET['export_tickets']) && current_user_can('manage_options')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'keycart_support_tickets';
        $tickets = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC", ARRAY_A);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="support_tickets_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, array('Ticket #', 'Date', 'Name', 'Email', 'Real Sender Email', 'Order #', 'Subject', 'Category', 'Priority', 'Status', 'Message'));
        
        foreach ($tickets as $ticket) {
            fputcsv($output, array(
                $ticket['ticket_number'],
                $ticket['created_at'],
                $ticket['name'],
                $ticket['email'],
                $ticket['real_sender_email'],
                $ticket['order_number'],
                $ticket['subject'],
                $ticket['category'],
                $ticket['priority'],
                $ticket['status'],
                $ticket['message']
            ));
        }
        
        fclose($output);
        exit;
    }
}

// ===============================================
// WOOCOMMERCE MY ACCOUNT INTEGRATION
// ===============================================

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    
    // Add Support Tickets endpoint to My Account
    add_action('init', 'kcst_add_my_account_endpoint');
    
    function kcst_add_my_account_endpoint() {
        add_rewrite_endpoint('support-tickets', EP_ROOT | EP_PAGES);
    }
    
    // Add menu item to My Account navigation
    add_filter('woocommerce_account_menu_items', 'kcst_add_my_account_menu_item');
    
    function kcst_add_my_account_menu_item($items) {
        $new_items = array();
        foreach($items as $key => $value) {
            $new_items[$key] = $value;
            if($key === 'orders') {
                $new_items['support-tickets'] = __('Support Tickets', 'keycart');
            }
        }
        return $new_items;
    }
    
    // Register query vars
    add_filter('query_vars', 'kcst_query_vars', 0);
    
    function kcst_query_vars($vars) {
        $vars[] = 'support-tickets';
        return $vars;
    }
    
    // Add content to the new endpoint
    add_action('woocommerce_account_support-tickets_endpoint', 'kcst_my_account_tickets_content');
    
    function kcst_my_account_tickets_content() {
        global $wpdb;
        $current_user = wp_get_current_user();
        $user_email = $current_user->user_email;
        $table_name = $wpdb->prefix . 'keycart_support_tickets';
        
        $ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;
        
        if ($ticket_id) {
            kcst_display_single_ticket($ticket_id, $user_email);
        } else {
            kcst_display_tickets_list($user_email);
        }
    }
    
    // Display tickets list - now shows tickets by real_sender_email
    function kcst_display_tickets_list($user_email) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'keycart_support_tickets';
        
        // Get tickets by real sender email OR email field
        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE real_sender_email = %s 
                OR (real_sender_email IS NULL AND email = %s)
                ORDER BY created_at DESC",
                $user_email,
                $user_email
            )
        );
        
        ?>
        <style>
            .kcst-tickets-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            .kcst-tickets-table th {
                background: #f5f5f5;
                padding: 12px;
                text-align: left;
                border-bottom: 2px solid #ddd;
                font-weight: 600;
            }
            .kcst-tickets-table td {
                padding: 12px;
                border-bottom: 1px solid #e0e0e0;
            }
            .kcst-tickets-table tr:hover {
                background: #f9f9f9;
            }
            .kcst-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 15px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .kcst-status-new {
                background: #fee2e2;
                color: #dc2626;
            }
            .kcst-status-in-progress {
                background: #fef3c7;
                color: #d97706;
            }
            .kcst-status-resolved {
                background: #d1fae5;
                color: #065f46;
            }
            .kcst-priority-urgent {
                color: #dc2626;
                font-weight: 600;
            }
            .kcst-priority-high {
                color: #ea580c;
                font-weight: 600;
            }
            .kcst-priority-normal {
                color: #2563eb;
            }
            .kcst-priority-low {
                color: #6b7280;
            }
            .kcst-view-btn {
                background: #007cba;
                color: white;
                padding: 6px 16px;
                text-decoration: none;
                border-radius: 4px;
                display: inline-block;
                font-size: 14px;
                transition: all 0.3s;
            }
            .kcst-view-btn:hover {
                background: #005a87;
                color: white;
                transform: translateY(-1px);
            }
            .kcst-empty-state {
                text-align: center;
                padding: 60px 20px;
                background: #f9fafb;
                border-radius: 8px;
                margin-top: 20px;
            }
            .kcst-empty-state h3 {
                color: #374151;
                margin-bottom: 10px;
            }
            .kcst-empty-state p {
                color: #6b7280;
                margin-bottom: 20px;
            }
            .kcst-new-ticket-btn {
                background: #10b981;
                color: white;
                padding: 12px 24px;
                text-decoration: none;
                border-radius: 6px;
                display: inline-block;
                font-weight: 600;
                transition: all 0.3s;
            }
            .kcst-new-ticket-btn:hover {
                background: #059669;
                color: white;
                transform: translateY(-2px);
            }
            .kcst-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #e5e7eb;
            }
            .kcst-header h2 {
                margin: 0;
                color: #1f2937;
            }
            .kcst-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                margin-bottom: 30px;
            }
            .kcst-stat-card {
                background: white;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
                text-align: center;
            }
            .kcst-stat-number {
                font-size: 24px;
                font-weight: bold;
                color: #007cba;
            }
            .kcst-stat-label {
                font-size: 14px;
                color: #6b7280;
                margin-top: 5px;
            }
        </style>
        
        <div class="kcst-my-account-tickets">
            <div class="kcst-header">
                <h2>My Support Tickets</h2>
                <a href="/customer-support/" class="kcst-new-ticket-btn">+ New Ticket</a>
            </div>
            
            <?php if (!empty($tickets)): ?>
                <?php
                $total = count($tickets);
                $new_count = count(array_filter($tickets, function($t) { return $t->status === 'new'; }));
                $in_progress = count(array_filter($tickets, function($t) { return $t->status === 'in-progress'; }));
                $resolved = count(array_filter($tickets, function($t) { return $t->status === 'resolved'; }));
                ?>
                
                <div class="kcst-stats">
                    <div class="kcst-stat-card">
                        <div class="kcst-stat-number"><?php echo $total; ?></div>
                        <div class="kcst-stat-label">Total Tickets</div>
                    </div>
                    <div class="kcst-stat-card">
                        <div class="kcst-stat-number" style="color: #dc2626;"><?php echo $new_count; ?></div>
                        <div class="kcst-stat-label">Awaiting Response</div>
                    </div>
                    <div class="kcst-stat-card">
                        <div class="kcst-stat-number" style="color: #d97706;"><?php echo $in_progress; ?></div>
                        <div class="kcst-stat-label">In Progress</div>
                    </div>
                    <div class="kcst-stat-card">
                        <div class="kcst-stat-number" style="color: #065f46;"><?php echo $resolved; ?></div>
                        <div class="kcst-stat-label">Resolved</div>
                    </div>
                </div>
                
                <table class="kcst-tickets-table">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td><strong><?php echo esc_html($ticket->ticket_number); ?></strong></td>
                                <td><?php echo esc_html($ticket->subject); ?></td>
                                <td><?php echo esc_html(ucfirst(str_replace('-', ' ', $ticket->category))); ?></td>
                                <td class="kcst-priority-<?php echo esc_attr($ticket->priority); ?>">
                                    <?php echo ucfirst(esc_html($ticket->priority)); ?>
                                </td>
                                <td>
                                    <span class="kcst-status-badge kcst-status-<?php echo esc_attr($ticket->status); ?>">
                                        <?php echo str_replace('-', ' ', esc_html($ticket->status)); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($ticket->created_at)); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('support-tickets') . '?ticket_id=' . $ticket->id); ?>" 
                                       class="kcst-view-btn">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="kcst-empty-state">
                    <div style="font-size: 64px; margin-bottom: 20px;">📭</div>
                    <h3>No Support Tickets Yet</h3>
                    <p>You haven't submitted any support tickets. Need help with something?</p>
                    <a href="/customer-support/" class="kcst-new-ticket-btn">Submit Your First Ticket</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    // Display single ticket - now checks by real_sender_email
    function kcst_display_single_ticket($ticket_id, $user_email) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'keycart_support_tickets';
        $replies_table = $wpdb->prefix . 'keycart_ticket_replies';
        
        // Check by real sender email OR email field
        $ticket = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE id = %d 
                AND (real_sender_email = %s OR (real_sender_email IS NULL AND email = %s))",
                $ticket_id,
                $user_email,
                $user_email
            )
        );
        
        if (!$ticket) {
            echo '<div class="woocommerce-error">Ticket not found or you don\'t have permission to view it.</div>';
            return;
        }
        
        if (isset($_POST['submit_reply']) && isset($_POST['reply_message'])) {
            $reply_message = sanitize_textarea_field($_POST['reply_message']);
            if (!empty($reply_message)) {
                $wpdb->insert(
                    $replies_table,
                    array(
                        'ticket_id' => $ticket_id,
                        'sender_type' => 'customer',
                        'sender_name' => wp_get_current_user()->display_name,
                        'message' => $reply_message,
                        'created_at' => current_time('mysql')
                    )
                );
                
                if ($ticket->status === 'resolved') {
                    $wpdb->update(
                        $table_name,
                        array('status' => 'new'),
                        array('id' => $ticket_id)
                    );
                }
                
                echo '<div class="woocommerce-message">Your reply has been submitted successfully.</div>';
                
                $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $ticket_id));
            }
        }
        
        $replies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $replies_table WHERE ticket_id = %d ORDER BY created_at ASC",
                $ticket_id
            )
        );
        
        ?>
        <style>
            .kcst-ticket-detail {
                background: white;
                padding: 30px;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
            }
            .kcst-ticket-header {
                border-bottom: 2px solid #e5e7eb;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .kcst-ticket-meta {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .kcst-meta-item {
                display: flex;
                flex-direction: column;
            }
            .kcst-meta-label {
                font-size: 12px;
                color: #6b7280;
                margin-bottom: 5px;
                text-transform: uppercase;
                font-weight: 600;
            }
            .kcst-meta-value {
                font-size: 16px;
                color: #1f2937;
                font-weight: 500;
            }
            .kcst-ticket-content {
                margin: 30px 0;
            }
            .kcst-message-box {
                background: #f9fafb;
                padding: 20px;
                border-radius: 8px;
                border-left: 4px solid #007cba;
                margin-bottom: 20px;
            }
            .kcst-message-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                padding-bottom: 10px;
                border-bottom: 1px solid #e5e7eb;
            }
            .kcst-message-sender {
                font-weight: 600;
                color: #1f2937;
            }
            .kcst-message-date {
                color: #6b7280;
                font-size: 14px;
            }
            .kcst-message-content {
                color: #374151;
                line-height: 1.6;
            }
            .kcst-admin-reply {
                background: #fef3c7;
                border-left-color: #f59e0b;
            }
            .kcst-customer-reply {
                background: #eff6ff;
                border-left-color: #3b82f6;
            }
            .kcst-reply-form {
                margin-top: 30px;
                padding: 20px;
                background: #f9fafb;
                border-radius: 8px;
            }
            .kcst-reply-form h3 {
                margin-top: 0;
                color: #1f2937;
            }
            .kcst-reply-form textarea {
                width: 100%;
                min-height: 120px;
                padding: 12px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-size: 15px;
                resize: vertical;
            }
            .kcst-reply-form button {
                background: #007cba;
                color: white;
                padding: 10px 24px;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                margin-top: 10px;
                transition: all 0.3s;
            }
            .kcst-reply-form button:hover {
                background: #005a87;
                transform: translateY(-1px);
            }
            .kcst-back-link {
                display: inline-block;
                margin-bottom: 20px;
                color: #007cba;
                text-decoration: none;
                font-weight: 500;
                transition: all 0.3s;
            }
            .kcst-back-link:hover {
                color: #005a87;
                transform: translateX(-3px);
            }
            .kcst-status-resolved-notice {
                background: #d1fae5;
                color: #065f46;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 20px;
                text-align: center;
                font-weight: 500;
            }
        </style>
        
        <a href="<?php echo esc_url(wc_get_account_endpoint_url('support-tickets')); ?>" class="kcst-back-link">
            ← Back to Tickets
        </a>
        
        <div class="kcst-ticket-detail">
            <div class="kcst-ticket-header">
                <h2>Ticket #<?php echo esc_html($ticket->ticket_number); ?></h2>
                <h3><?php echo esc_html($ticket->subject); ?></h3>
                
                <div class="kcst-ticket-meta">
                    <div class="kcst-meta-item">
                        <span class="kcst-meta-label">Status</span>
                        <span class="kcst-meta-value">
                            <span class="kcst-status-badge kcst-status-<?php echo esc_attr($ticket->status); ?>">
                                <?php echo str_replace('-', ' ', esc_html($ticket->status)); ?>
                            </span>
                        </span>
                    </div>
                    <div class="kcst-meta-item">
                        <span class="kcst-meta-label">Priority</span>
                        <span class="kcst-meta-value kcst-priority-<?php echo esc_attr($ticket->priority); ?>">
                            <?php echo ucfirst(esc_html($ticket->priority)); ?>
                        </span>
                    </div>
                    <div class="kcst-meta-item">
                        <span class="kcst-meta-label">Category</span>
                        <span class="kcst-meta-value"><?php echo ucfirst(str_replace('-', ' ', esc_html($ticket->category))); ?></span>
                    </div>
                    <div class="kcst-meta-item">
                        <span class="kcst-meta-label">Created</span>
                        <span class="kcst-meta-value"><?php echo date('M d, Y at H:i', strtotime($ticket->created_at)); ?></span>
                    </div>
                    <?php if (!empty($ticket->order_number)): ?>
                    <div class="kcst-meta-item">
                        <span class="kcst-meta-label">Order Number</span>
                        <span class="kcst-meta-value"><?php echo esc_html($ticket->order_number); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="kcst-ticket-content">
                <h3>Conversation</h3>
                
                <div class="kcst-message-box">
                    <div class="kcst-message-header">
                        <span class="kcst-message-sender">You</span>
                        <span class="kcst-message-date"><?php echo date('M d, Y at H:i', strtotime($ticket->created_at)); ?></span>
                    </div>
                    <div class="kcst-message-content">
                        <?php echo nl2br(esc_html($ticket->message)); ?>
                    </div>
                </div>
                
                <?php if (!empty($replies)): ?>
                    <?php foreach ($replies as $reply): ?>
                        <div class="kcst-message-box <?php echo $reply->sender_type === 'admin' ? 'kcst-admin-reply' : 'kcst-customer-reply'; ?>">
                            <div class="kcst-message-header">
                                <span class="kcst-message-sender">
                                    <?php echo $reply->sender_type === 'admin' ? 'Support Team' : 'You'; ?>
                                </span>
                                <span class="kcst-message-date"><?php echo date('M d, Y at H:i', strtotime($reply->created_at)); ?></span>
                            </div>
                            <div class="kcst-message-content">
                                <?php echo nl2br(esc_html($reply->message)); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($ticket->status !== 'resolved'): ?>
                <div class="kcst-reply-form">
                    <h3>Add a Reply</h3>
                    <form method="post">
                        <textarea name="reply_message" placeholder="Type your message here..." required></textarea>
                        <button type="submit" name="submit_reply">Send Reply</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="kcst-status-resolved-notice">
                    ✓ This ticket has been resolved. If you need further assistance, please submit a new ticket.
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    // Add custom icon to menu item
    add_action('wp_head', 'kcst_custom_my_account_styles');
    
    function kcst_custom_my_account_styles() {
        ?>
        <style>
            .woocommerce-MyAccount-navigation-link--support-tickets a:before {
                content: "🎫";
                margin-right: 5px;
            }
        </style>
        <?php
    }
}

// Clean up on plugin deactivation
register_deactivation_hook(__FILE__, 'kcst_deactivation');

function kcst_deactivation() {
    // Remove rewrite rules
    flush_rewrite_rules();
}
?>