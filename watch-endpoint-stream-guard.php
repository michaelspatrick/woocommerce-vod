<?php
/**
 * DSI VOD Watch Endpoint — BAREBONES (Resolver Edition v2)
 * --------------------------------------------------------
 * - No tokens, no proxy. Plays resolved URL.
 * - Robust ownership: checks product, parent, and all child variations.
 * - Admin bypass: ?force=1 (admins only)
 * - Debug: ?dbg=1 shows checked IDs and resolved key.
 */

if (!defined('ABSPATH')) { exit; }

/** ===== Routing ===== */
add_action('init', function() {
    add_rewrite_rule('^my-account/watch/([0-9]+)/?$', 'index.php?dsi_vod_watch_bare=1&product_id=$matches[1]', 'top');
    add_rewrite_tag('%dsi_vod_watch_bare%','([^&]+)');
    add_rewrite_tag('%product_id%','([0-9]+)');
});

/** ===== Helper: collect candidate IDs (self, parent, children) ===== */
function dsi_vod_collect_candidate_ids($product_id) {
    $ids = [];
    $ids[] = (int)$product_id;
    if (function_exists('wc_get_product')) {
        $p = wc_get_product($product_id);
        if ($p) {
            if ($p->is_type('variation')) {
                $parent_id = $p->get_parent_id();
                if ($parent_id) $ids[] = (int)$parent_id;
            } else {
                // add variations
                if (method_exists($p, 'get_children')) {
                    $children = (array)$p->get_children();
                    foreach ($children as $cid) { $ids[] = (int)$cid; }
                }
            }
        }
    } else {
        // fallback: try WordPress parent
        $parent = wp_get_post_parent_id($product_id);
        if ($parent) $ids[] = (int)$parent;
    }
    // unique
    $ids = array_values(array_unique(array_filter($ids)));
    return $ids;
}

/** ===== Ownership check across candidates ===== */
function dsi_vod_owns_any($user_id, $product_id) {
    $candidates = dsi_vod_collect_candidate_ids($product_id);

    // If site defines a canonical checker, try it for each ID
    if (function_exists('dsi_vod_user_owns_product')) {
        foreach ($candidates as $pid) {
            if (dsi_vod_user_owns_product($user_id, $pid)) return true;
        }
    }

    global $wpdb;
    // Custom mapping table if present
    $tbl = $wpdb->prefix . 'woocommerce_vod';
    $has_tbl = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl));
    if ($has_tbl) {
        $in = implode(',', array_fill(0, count($candidates), '%d'));
        $sql = "SELECT 1 FROM {$tbl} WHERE user_id=%d AND product_id IN ($in) LIMIT 1";
        $args = array_merge([$user_id], $candidates);
        $row = (int)$wpdb->get_var($wpdb->prepare($sql, $args));
        if ($row === 1) return true;
    }

    // Woo orders scan fallback
    if (function_exists('wc_get_orders')) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => 50,
            'status'      => ['processing','completed'],
            'return'      => 'ids',
        ]);
        if ($orders) {
            foreach ($orders as $oid) {
                $order = wc_get_order($oid);
                if (!$order) continue;
                foreach ($order->get_items() as $item) {
                    $pid = (int)$item->get_product_id();
                    $vid = (int)$item->get_variation_id();
                    foreach ($candidates as $cand) {
                        if ($pid === $cand || ($vid && $vid === $cand)) return true;
                    }
                }
            }
        }
    }

    return false;
}

/** ===== Resolver ===== */
function dsi_vod_bare_resolve_url($product_id, &$debug_info = null) {
    $debug = ['source'=>'', 'key'=>'', 'url'=>'', 'checked_keys'=>[]];

    if (!function_exists('wc_get_product')) return '';

    $product = wc_get_product($product_id);
    if (!$product) return '';

    if (function_exists('dsi_vod_resolve_stream_url')) {
        $url = (string) dsi_vod_resolve_stream_url($product);
        if ($url) { $debug['source']='resolver'; $debug['url']=$url; if (is_array($debug_info)) $debug_info=$debug; return $url; }
    }

    // Primary keys (product then parent)
    $primary_keys = ['_vod_stream_url','_video_url','_video_src','_stream_url'];
    foreach ($primary_keys as $k) { $debug['checked_keys'][] = $k; $v = (string) $product->get_meta($k, true); if ($v) { $debug['source']='product'; $debug['key']=$k; $debug['url']=$v; if (is_array($debug_info)) $debug_info=$debug; return $v; } }
    if ($product->is_type('variation')) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) {
            foreach ($primary_keys as $k) { $debug['checked_keys'][] = "parent:$k"; $v = (string) $parent->get_meta($k, true); if ($v) { $debug['source']='parent'; $debug['key']=$k; $debug['url']=$v; if (is_array($debug_info)) $debug_info=$debug; return $v; } }
        }
    }

    // Fallback keys
    $fallbacks = ['_vod_mp4_url','_vod_s3_url','_dsi_vod_stream_url'];
    foreach ($fallbacks as $k) { $debug['checked_keys'][] = $k; $v = (string) $product->get_meta($k, true); if ($v) { $debug['source']='product-fallback'; $debug['key']=$k; $debug['url']=$v; if (is_array($debug_info)) $debug_info=$debug; return $v; } }
    if ($product->is_type('variation')) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) {
            foreach ($fallbacks as $k) { $debug['checked_keys'][] = "parent:$k"; $v = (string) $parent->get_meta($k, true); if ($v) { $debug['source']='parent-fallback'; $debug['key']=$k; $debug['url']=$v; if (is_array($debug_info)) $debug_info=$debug; return $v; } }
        }
    }

    if (is_array($debug_info)) $debug_info=$debug;
    return '';
}

/** ===== Page ===== */
add_action('template_redirect', function() {
    if (get_query_var('dsi_vod_watch_bare') !== '1') return;

    $dbg   = isset($_GET['dbg']) && $_GET['dbg'] === '1';
    $force = isset($_GET['force']) && $_GET['force'] === '1' && current_user_can('manage_options');

    if (!is_user_logged_in()) { auth_redirect(); return; }

    $user_id    = get_current_user_id();
    $product_id = (int)get_query_var('product_id');

    $owns = dsi_vod_owns_any($user_id, $product_id);
    if (!$owns && !$force) {
        wp_die(esc_html__('You do not have access to this video.', 'woocommerce_vod'), 403);
    }

    $debug_info = [];
    $url = dsi_vod_bare_resolve_url($product_id, $debug_info);

    // Output page
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache'); header('Expires: 0');
    status_header(200);
    header('Content-Type: text/html; charset=utf-8');

    $is_hls = is_string($url) && preg_match('/\.m3u8(\?|$)/i', $url);
    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Watch</title>
<style>
html,body{height:100%;margin:0;background:#000;color:#ccc;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
.wrap{height:100%}
video{width:100%;height:100%;background:#000}
.msg{max-width:760px;margin:2rem auto;padding:1rem 1.25rem;background:#141414;border:1px solid #333;border-radius:8px}
.debug{position:fixed;bottom:10px;left:10px;right:10px;background:#111;border:1px solid #333;border-radius:8px;padding:8px 12px;font-size:12px;line-height:1.4;max-height:40vh;overflow:auto}
.debug code{color:#9fd}
</style>
<?php if ($is_hls): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.15/dist/hls.min.js"></script>
<?php endif; ?>
</head>
<body>
<?php if (!$url): ?>
    <div class="msg">
        <h2>Video not configured</h2>
        <p>No video URL was found on this product (or its parent). Checked keys:</p>
        <p><code><?php echo esc_html(implode(', ', $debug_info['checked_keys'] ?? [])); ?></code></p>
    </div>
<?php else: ?>
    <div class="wrap">
        <video id="vodPlayer" controls playsinline autoplay <?php echo $is_hls ? '' : 'src="'.esc_url($url).'"'; ?>></video>
    </div>
    <?php if ($is_hls): ?>
    <script>
    (function(){
        var src = <?php echo json_encode($url); ?>;
        var v = document.getElementById('vodPlayer');
        if (v.canPlayType('application/vnd.apple.mpegurl')) {
            v.src = src; v.load(); v.play().catch(()=>{});
        } else if (window.Hls && window.Hls.isSupported()) {
            var hls = new Hls({lowLatencyMode:false});
            hls.loadSource(src); hls.attachMedia(v); v.play().catch(()=>{});
        } else {
            v.src = src; v.load(); v.play().catch(()=>{});
        }
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>

<?php if ($dbg): ?>
<div class="debug">
<strong>Debug</strong><br>
Checked IDs: <code><?php echo esc_html(implode(', ', dsi_vod_collect_candidate_ids($product_id))); ?></code><br>
Resolved source: <code><?php echo esc_html($debug_info['source'] ?? ''); ?></code> key: <code><?php echo esc_html($debug_info['key'] ?? ''); ?></code><br>
URL: <code><?php echo esc_html($debug_info['url'] ?? ''); ?></code>
</div>
<?php endif; ?>
</body>
</html>
<?php
    exit;
});

