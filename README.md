# WooCommerce VOD (Video-On-Demand) Access Manager

A WordPress/WooCommerce plugin for selling and managing access to streaming video products (VODs).  
Securely grants streaming access after purchase, protects video URLs, and lets administrators view, grant, or revoke access.

---

## Features

- **Automatic Access** – When a customer purchases a qualifying VOD product, access is automatically granted.
- **WooCommerce Integration** – Hooks into order completion/payment events.
- **Admin Access Management**
  - Central **WooCommerce → VOD Access** page to search users/products and grant/revoke access.
  - Integrated tools in **Order Admin** to check or adjust access for streaming video items.
- **Customer Video Playback**
  - Adds a **"Streaming Video"** tab to the product page for customers who purchased the video.
  - Optional dedicated viewing page with embedded player.
  - Supports secure playback (prevents direct file downloads).
- **Secure Database Tracking** – All access records stored in a `wp_woocommerce_vod` table.
- **Manual Overrides** – Grant or revoke access at any time from the admin.
- **Reason Logging** – Store an admin-provided reason when revoking access.
- **AJAX Search & Pagination** – Product and user selection fields use Select2 with AJAX for large catalogs.

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+ (PHP 8.1+ recommended)
- MySQL 5.7+ or MariaDB 10+

---

## Installation

1. Upload the plugin folder to:
   ```
   wp-content/plugins/woocommerce-vod
   ```
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Ensure WooCommerce is installed and active.
4. Create at least one **VOD Product**:
   - Set the product type to `vod` (or a variable product variation with VOD attributes).
5. Configure your video hosting and embed settings in the plugin’s settings (if applicable).

---

## Usage

### Selling a VOD Product
- Create a WooCommerce product of type **VOD** or assign a VOD variation.
- Once a customer purchases, the plugin inserts a record into `wp_woocommerce_vod` linking the user and product.
- The **Streaming Video** tab will automatically appear for the customer on that product page.

### Watching Purchased Videos
- Customers can:
  - Visit the product page after logging in.
  - Or access a dedicated VOD viewing page (if enabled by admin).

### Admin Management
- Navigate to **WooCommerce → VOD Access** to:
  - Search for a user/product.
  - Grant or revoke streaming access.
  - Provide a reason for revocations.
- On the **WooCommerce Order Edit** page:
  - See whether the customer has access to each streaming item.
  - Grant or revoke access for the entire order.

---

## Database Table

The plugin creates a custom table:

```sql
CREATE TABLE `wp_woocommerce_vod` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `ts` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_product` (`user_id`, `product_id`)
) DEFAULT CHARSET=utf8mb4;
```

---

## Security

- Uses WooCommerce hooks for safe integration.
- Prevents direct download links by embedding videos in a secure player.
- Admin pages require `manage_woocommerce` capability.
- Nonces used on all grant/revoke actions.

---

## Developer Notes

### Hooks
- `woocommerce_process_vod` – Handles VOD table inserts after payment completion.
- `dsi_vod_has_access( $user_id, $product_id )` – Check if a user has access to a given product.
- `dsi_vod_grant_access( $user_id, $product_id, $reason = '' )` – Programmatically grant access.
- `dsi_vod_revoke_access( $user_id, $product_id, $reason = '' )` – Programmatically revoke access.

### WP-CLI Commands
(If enabled)
- `wp vod backfill` – Scan past paid orders, grant missing VOD rows, report conflicts.
- `wp vod check` – Compare VOD table to WooCommerce orders, list discrepancies.

---

## Changelog

### 1.0.0
- Initial public release.
- Automatic VOD access on purchase.
- Admin VOD Access page with AJAX search.
- Streaming Video product tab for purchasers.
- Order page integration for access control.

---

## License
GPL-2.0-or-later

