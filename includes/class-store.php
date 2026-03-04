<?php
namespace Procyon\LikeBag;

if (!defined('ABSPATH')) exit;

class Store {
    public const COOKIE_NAME = 'procyon_like_bag_token';
    public const CRON_CLEANUP_HOOK = 'procyon_like_bag_cleanup_guests';
    private const DEFAULT_GUEST_RETENTION_DAYS = 180;

    public static function init_hooks(): void {
        add_action('wp_login', [__CLASS__, 'handle_wp_login'], 10, 2);
        add_action('before_delete_post', [__CLASS__, 'on_post_delete'], 10, 1);
        add_action('trashed_post', [__CLASS__, 'on_post_delete'], 10, 1);
        add_action('transition_post_status', [__CLASS__, 'on_post_status_transition'], 10, 3);
        add_action(self::CRON_CLEANUP_HOOK, [__CLASS__, 'cleanup_old_guest_rows']);

        self::ensure_cleanup_schedule();
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'procyon_like_bag';
    }

    public static function install_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            guest_token varchar(64) NOT NULL DEFAULT '',
            product_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_actor_product (user_id, guest_token, product_id),
            KEY idx_user (user_id),
            KEY idx_guest (guest_token),
            KEY idx_product (product_id)
        ) {$collate};";

        dbDelta($sql);
        update_option('procyon_like_bag_table_version', PROCYON_LIKE_BAG_TABLE_VERSION, false);
    }

    public static function handle_wp_login(string $user_login, \WP_User $user): void {
        unset($user_login);

        $token = self::read_cookie_token();
        if ($token === '') return;

        $merge = self::merge_guest_into_user($token, (int) $user->ID);
        if (!is_wp_error($merge)) {
            self::forget_guest_cookie();
        }
    }

    public static function on_post_delete(int $post_id): void {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) return;
        if (!in_array($post->post_type, ['product', 'product_variation'], true)) return;

        self::delete_product_from_all_bags((int) $post_id);
    }

    public static function on_post_status_transition(string $new_status, string $old_status, \WP_Post $post): void {
        if (!in_array($post->post_type, ['product', 'product_variation'], true)) return;
        if ($old_status === $new_status) return;

        // Keep only published products in favorites to avoid stale/private items.
        if ($old_status === 'publish' && $new_status !== 'publish') {
            self::delete_product_from_all_bags((int) $post->ID);
        }
    }

    public static function ensure_cleanup_schedule(): void {
        if (wp_next_scheduled(self::CRON_CLEANUP_HOOK)) return;
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEANUP_HOOK);
    }

    public static function clear_cleanup_schedule(): void {
        $ts = wp_next_scheduled(self::CRON_CLEANUP_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_CLEANUP_HOOK);
            $ts = wp_next_scheduled(self::CRON_CLEANUP_HOOK);
        }
    }

    public static function cleanup_old_guest_rows(): int {
        global $wpdb;

        $days = (int) apply_filters('procyon_like_bag_guest_retention_days', self::DEFAULT_GUEST_RETENTION_DAYS);
        $days = min(max(7, $days), 3650);

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $table = self::table();

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = 0 AND updated_at < %s",
                $cutoff
            )
        );

        if ($deleted === false) return 0;
        return (int) $deleted;
    }

    public static function delete_product_from_all_bags(int $product_id): int {
        global $wpdb;

        if ($product_id <= 0) return 0;

        $deleted = $wpdb->delete(self::table(), ['product_id' => $product_id], ['%d']);
        if ($deleted === false) return 0;
        return (int) $deleted;
    }

    public static function resolve_actor(\WP_REST_Request $req, bool $create_guest_token = false): array {
        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            $user_id = self::resolve_user_id_from_auth_cookie($req);
        }

        $guest_token = self::extract_token($req);

        if ($user_id > 0) {
            return [
                'source' => 'user',
                'user_id' => $user_id,
                'guest_token' => $guest_token,
            ];
        }

        if ($guest_token === '' && $create_guest_token) {
            $guest_token = self::generate_token();
        }

        return [
            'source' => 'guest',
            'user_id' => 0,
            'guest_token' => $guest_token,
        ];
    }

    private static function resolve_user_id_from_auth_cookie(\WP_REST_Request $req): int {
        if (!defined('LOGGED_IN_COOKIE')) return 0;
        if (empty($_COOKIE[LOGGED_IN_COOKIE])) return 0;
        if (!self::is_same_origin_request($req)) return 0;

        $user_id = (int) wp_validate_auth_cookie('', 'logged_in');
        if ($user_id <= 0) return 0;

        wp_set_current_user($user_id);
        return $user_id;
    }

    private static function is_same_origin_request(\WP_REST_Request $req): bool {
        $allowed_hosts = array_values(array_unique(array_filter(array_map(
            function ($v): string {
                return strtolower(trim((string) $v));
            },
            [
                wp_parse_url(home_url('/'), PHP_URL_HOST),
                wp_parse_url(site_url('/'), PHP_URL_HOST),
                $_SERVER['HTTP_HOST'] ?? '',
            ]
        ))));
        if (!$allowed_hosts) return false;

        $origin = trim((string) $req->get_header('origin'));
        if ($origin !== '') {
            $origin_host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
            if ($origin_host === '' || !in_array($origin_host, $allowed_hosts, true)) return false;

            return true;
        }

        $referer = trim((string) $req->get_header('referer'));
        if ($referer !== '') {
            $referer_host = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
            if ($referer_host === '' || !in_array($referer_host, $allowed_hosts, true)) return false;

            return true;
        }

        $method = strtoupper((string) $req->get_method());
        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public static function extract_token(\WP_REST_Request $req): string {
        $header = (string) $req->get_header('X-Procyon-Like-Bag-Token');
        $param = (string) $req->get_param('token');
        $cookie = self::read_cookie_token();

        $token = self::sanitize_token($header);
        if ($token !== '') return $token;

        $token = self::sanitize_token($param);
        if ($token !== '') return $token;

        return $cookie;
    }

    public static function sanitize_token($token): string {
        $token = strtolower(trim((string) $token));
        if ($token === '') return '';
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return '';
        return $token;
    }

    public static function read_cookie_token(): string {
        if (!isset($_COOKIE[self::COOKIE_NAME])) return '';
        return self::sanitize_token(wp_unslash((string) $_COOKIE[self::COOKIE_NAME]));
    }

    public static function generate_token(): string {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            unset($e);
            return md5(wp_generate_uuid4() . '|' . wp_rand() . '|' . microtime(true));
        }
    }

    public static function persist_guest_token(string $token): void {
        $token = self::sanitize_token($token);
        if ($token === '') return;
        if (headers_sent()) return;

        $expire = time() + YEAR_IN_SECONDS;
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();
        $http_only = true;

        setcookie(self::COOKIE_NAME, $token, $expire, $path, $domain, $secure, $http_only);
        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== $path) {
            setcookie(self::COOKIE_NAME, $token, $expire, SITECOOKIEPATH, $domain, $secure, $http_only);
        }

        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    public static function forget_guest_cookie(): void {
        if (headers_sent()) return;

        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();
        $http_only = true;
        $expire = time() - HOUR_IN_SECONDS;

        setcookie(self::COOKIE_NAME, '', $expire, $path, $domain, $secure, $http_only);
        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== $path) {
            setcookie(self::COOKIE_NAME, '', $expire, SITECOOKIEPATH, $domain, $secure, $http_only);
        }

        unset($_COOKIE[self::COOKIE_NAME]);
    }

    public static function add_product(array $actor, int $product_id) {
        global $wpdb;

        $identity = self::normalize_actor_for_write($actor);
        if (is_wp_error($identity)) return $identity;
        if (!self::is_valid_product($product_id)) {
            return new \WP_Error('invalid_product', 'Product does not exist.', ['status' => 404]);
        }

        $table = self::table();
        $now = current_time('mysql', true);
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (user_id, guest_token, product_id, created_at, updated_at)
                 VALUES (%d, %s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                $identity['user_id'],
                $identity['guest_token'],
                $product_id,
                $now,
                $now
            )
        );

        if ($result === false) {
            return new \WP_Error('db_write_failed', 'Could not add product to like bag.', ['status' => 500]);
        }

        return true;
    }

    public static function remove_product(array $actor, int $product_id) {
        global $wpdb;

        $identity = self::normalize_actor_for_read($actor);
        if ($identity === null) return false;

        $table = self::table();
        if ($identity['user_id'] > 0) {
            $sql = $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d AND guest_token = '' AND product_id = %d",
                $identity['user_id'],
                $product_id
            );
        } else {
            $sql = $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = 0 AND guest_token = %s AND product_id = %d",
                $identity['guest_token'],
                $product_id
            );
        }

        $result = $wpdb->query($sql);
        if ($result === false) {
            return new \WP_Error('db_write_failed', 'Could not remove product from like bag.', ['status' => 500]);
        }

        return ((int) $result) > 0;
    }

    public static function has_product(array $actor, int $product_id) {
        global $wpdb;

        $identity = self::normalize_actor_for_read($actor);
        if ($identity === null) return false;

        $table = self::table();
        if ($identity['user_id'] > 0) {
            $sql = $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE user_id = %d AND guest_token = '' AND product_id = %d LIMIT 1",
                $identity['user_id'],
                $product_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE user_id = 0 AND guest_token = %s AND product_id = %d LIMIT 1",
                $identity['guest_token'],
                $product_id
            );
        }

        $row = $wpdb->get_var($sql);
        return !is_null($row);
    }

    public static function toggle_product(array $actor, int $product_id) {
        $has = self::has_product($actor, $product_id);
        if (is_wp_error($has)) return $has;

        if ($has) {
            $removed = self::remove_product($actor, $product_id);
            if (is_wp_error($removed)) return $removed;
            return 'removed';
        }

        $added = self::add_product($actor, $product_id);
        if (is_wp_error($added)) return $added;
        return 'added';
    }

    public static function clear_actor(array $actor) {
        global $wpdb;

        $identity = self::normalize_actor_for_read($actor);
        if ($identity === null) return 0;

        $table = self::table();
        if ($identity['user_id'] > 0) {
            $sql = $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d AND guest_token = ''",
                $identity['user_id']
            );
        } else {
            $sql = $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = 0 AND guest_token = %s",
                $identity['guest_token']
            );
        }

        $result = $wpdb->query($sql);
        if ($result === false) {
            return new \WP_Error('db_write_failed', 'Could not clear like bag.', ['status' => 500]);
        }

        return (int) $result;
    }

    public static function count_products(array $actor): int {
        global $wpdb;

        $identity = self::normalize_actor_for_read($actor);
        if ($identity === null) return 0;

        $table = self::table();
        $posts = $wpdb->posts;
        [$actor_where, $actor_params] = self::build_actor_where_sql($identity, 'lb');

        $sql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table} lb
             INNER JOIN {$posts} p ON p.ID = lb.product_id
             LEFT JOIN {$posts} parent ON p.post_type = 'product_variation' AND parent.ID = p.post_parent
             WHERE {$actor_where}
               AND p.post_status = 'publish'
               AND (
                    p.post_type = 'product'
                    OR (p.post_type = 'product_variation' AND parent.post_type = 'product' AND parent.post_status = 'publish')
               )",
            ...$actor_params
        );
        return (int) $wpdb->get_var($sql);
    }

    public static function list_product_ids(array $actor): array {
        global $wpdb;

        $identity = self::normalize_actor_for_read($actor);
        if ($identity === null) return [];

        $table = self::table();
        $posts = $wpdb->posts;
        [$actor_where, $actor_params] = self::build_actor_where_sql($identity, 'lb');
        $sql = $wpdb->prepare(
            "SELECT lb.product_id
             FROM {$table} lb
             INNER JOIN {$posts} p ON p.ID = lb.product_id
             LEFT JOIN {$posts} parent ON p.post_type = 'product_variation' AND parent.ID = p.post_parent
             WHERE {$actor_where}
               AND p.post_status = 'publish'
               AND (
                    p.post_type = 'product'
                    OR (p.post_type = 'product_variation' AND parent.post_type = 'product' AND parent.post_status = 'publish')
               )
             ORDER BY lb.updated_at DESC",
            ...$actor_params
        );

        $rows = $wpdb->get_col($sql);
        if (!is_array($rows)) return [];

        return array_map('intval', $rows);
    }

    public static function merge_guest_into_user(string $token, int $user_id) {
        global $wpdb;

        $token = self::sanitize_token($token);
        $user_id = (int) $user_id;
        if ($token === '' || $user_id <= 0) {
            return [
                'guest_rows' => 0,
                'merged_new' => 0,
                'deleted_guest_rows' => 0,
            ];
        }

        $table = self::table();
        $now = current_time('mysql', true);

        $guest_rows = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = 0 AND guest_token = %s",
                $token
            )
        );
        if ($guest_rows <= 0) {
            return [
                'guest_rows' => 0,
                'merged_new' => 0,
                'deleted_guest_rows' => 0,
            ];
        }

        $mergeable_new = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table} g
                 LEFT JOIN {$table} u
                    ON u.user_id = %d
                   AND u.guest_token = ''
                   AND u.product_id = g.product_id
                 WHERE g.user_id = 0
                   AND g.guest_token = %s
                   AND u.id IS NULL",
                $user_id,
                $token
            )
        );

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (user_id, guest_token, product_id, created_at, updated_at)
                 SELECT %d AS user_id, '' AS guest_token, src.product_id, %s, %s
                 FROM {$table} src
                 WHERE src.user_id = 0 AND src.guest_token = %s
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                $user_id,
                $now,
                $now,
                $token
            )
        );
        if ($inserted === false) {
            return new \WP_Error('db_merge_failed', 'Could not merge guest like bag into user account.', ['status' => 500]);
        }

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = 0 AND guest_token = %s",
                $token
            )
        );

        if ($deleted === false) {
            return new \WP_Error('db_merge_cleanup_failed', 'Guest like bag could not be cleaned after merge.', ['status' => 500]);
        }

        return [
            'guest_rows' => $guest_rows,
            'merged_new' => max(0, $mergeable_new),
            'deleted_guest_rows' => (int) $deleted,
        ];
    }

    public static function is_valid_product(int $product_id): bool {
        if ($product_id <= 0) return false;

        $post = get_post($product_id);
        if (!$post instanceof \WP_Post) return false;
        if (!in_array($post->post_type, ['product', 'product_variation'], true)) return false;
        if ($post->post_status !== 'publish') return false;

        if ($post->post_type === 'product_variation') {
            $parent_id = (int) $post->post_parent;
            if ($parent_id <= 0) return false;

            $parent = get_post($parent_id);
            if (!$parent instanceof \WP_Post) return false;
            if ($parent->post_type !== 'product') return false;
            if ($parent->post_status !== 'publish') return false;
        }

        return true;
    }

    private static function normalize_actor_for_write(array $actor) {
        $user_id = isset($actor['user_id']) ? (int) $actor['user_id'] : 0;
        if ($user_id > 0) {
            return [
                'user_id' => $user_id,
                'guest_token' => '',
            ];
        }

        $guest_token = self::sanitize_token($actor['guest_token'] ?? '');
        if ($guest_token === '') {
            return new \WP_Error('missing_guest_token', 'Guest token is required.', ['status' => 400]);
        }

        return [
            'user_id' => 0,
            'guest_token' => $guest_token,
        ];
    }

    private static function normalize_actor_for_read(array $actor): ?array {
        $user_id = isset($actor['user_id']) ? (int) $actor['user_id'] : 0;
        if ($user_id > 0) {
            return [
                'user_id' => $user_id,
                'guest_token' => '',
            ];
        }

        $guest_token = self::sanitize_token($actor['guest_token'] ?? '');
        if ($guest_token === '') return null;

        return [
            'user_id' => 0,
            'guest_token' => $guest_token,
        ];
    }

    private static function build_actor_where_sql(array $identity, string $alias): array {
        if ((int) $identity['user_id'] > 0) {
            return [
                "{$alias}.user_id = %d AND {$alias}.guest_token = ''",
                [(int) $identity['user_id']],
            ];
        }

        return [
            "{$alias}.user_id = 0 AND {$alias}.guest_token = %s",
            [(string) $identity['guest_token']],
        ];
    }
}
