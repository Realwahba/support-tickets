# support-tickets
Complete support ticket system with WooCommerce My Account integration, admin dashboard, and email notifications
# KeyCart Support Ticket System

Complete support ticket system with WooCommerce My Account integration.

## Features
- Support ticket submission (login required for security)
- WooCommerce My Account integration
- Admin ticket management dashboard
- Two-way reply system
- Email notifications
- Ticket status tracking (New, In Progress, Resolved)
- Priority levels (Low, Normal, High, Urgent)
- Category classification
- CSV export functionality
- Sequential ticket numbering (KC-YYYY-XXXX format)

## Installation
1. Download the plugin
2. Upload to `/wp-content/plugins/`
3. Activate through WordPress admin
4. Plugin automatically creates required database tables

## Usage

### For Customers
- Use shortcode `[keycart_support_form]` on any page
- Or access via WooCommerce My Account → Support Tickets
- Login required for ticket submission

### For Admins
- Access via **Support Tickets** menu in admin
- View, reply, edit tickets
- Update status and priority
- Export tickets to CSV

## Database Tables
Creates two tables:
- `{prefix}_keycart_support_tickets`
- `{prefix}_keycart_ticket_replies`

## Requirements
- WordPress 5.0+
- WooCommerce 6.0+ (for My Account integration)
- PHP 7.4+

## Author
Wahba - KeyCart.net

## Version
3.0.0
