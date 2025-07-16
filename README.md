# WooCommerce VOD Plugin

This WordPress plugin adds **Video on Demand (VOD)** capabilities to your WooCommerce store, allowing you to sell access to streaming video content directly through WooCommerce products.

Built with ease of use and security in mind, the plugin provides an admin interface for uploading and managing video files, associates videos with WooCommerce products, and allows customers to stream purchased content from their account page.

---

## 🎬 Features

- Adds a new product type for Video on Demand (VOD)
- Secure download or streaming links via shortcode and customer account page
- Admin panel for uploading videos and associating them with products
- Optional hiding of VOD content from product pages until purchased
- Supports large file hosting externally or within WordPress
- Designed to integrate natively with WooCommerce order handling

---

## 📂 File Overview

| File                         | Description                                         |
|------------------------------|-----------------------------------------------------|
| `woocommerce-vod-plugin.php` | Main plugin loader and initializer                  |
| `product-type.php`           | Registers custom WooCommerce product type "VOD"     |
| `product.php`                | Adds video fields and logic to product editor       |
| `custom-fields.php`          | Defines and handles extra product fields            |
| `vod-admin.php`              | Admin interface to upload and manage VOD content    |
| `woocommerce-vod-func.php`   | Core logic for accessing video content              |
| `my-account.php`             | Displays purchased videos on the My Account page    |
| `download-video.php`         | Secure video download/streaming handler             |
| `hide.php`                   | Logic to restrict access to videos until purchased  |
| `admin.php`                  | Adds plugin admin menu items                        |

---

## 🚀 Installation

1. Upload the plugin folder to `/wp-content/plugins/woocommerce-vod-plugin`
2. Activate the plugin through the WordPress Plugins menu
3. Ensure WooCommerce is installed and active
4. Create a new product and set its type to **VOD**
5. Upload your video file(s) via the **VOD Admin** menu or use external URLs
6. Assign the video(s) to your VOD product
7. Done! Users will get access to the video(s) in their account after purchase

---

## 🧩 Shortcodes

You can use the following shortcode to manually display a VOD video:

```php
[vod_video id="123"]
```

Where `123` is the WooCommerce product ID.

---

## 🛡️ Security Notes

- Videos are only accessible to users who have purchased the associated product
- Downloads/streams are handled through a secure PHP endpoint
- You may optionally store videos outside the public directory

---

## 🧑‍💻 Developer Notes

This plugin hooks into several WooCommerce filters and actions:

- `woocommerce_product_data_tabs`
- `woocommerce_process_product_meta`
- `woocommerce_account_menu_items`
- `woocommerce_account_endpoint`

Easily extendable and modular for custom workflows.

---

## 📌 Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

---

## 📃 License

This plugin is released under the [MIT License](LICENSE).

---

## 🧰 Credits

Developed by Michael Patrick.

Feel free to contribute via pull request or reach out with suggestions or issues.
