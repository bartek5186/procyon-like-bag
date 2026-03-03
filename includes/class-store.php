<?php
namespace Procyon\LikeBag;

if (!defined('ABSPATH')) exit;

class Store {
    public const COOKIE_NAME = 'procyon_like_bag_token';

    public static function init_hooks(): void {
        add_action('wp_login', [__CLASS__, 'handle_wp_login'], 10, 2);
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

        self::merge_guest_into_user($token, (int) $user->ID);
        self::forget_guest_cookie();
    }

    public static function resolve_actor(\WP_REST_Request $req, bool $create_guest_token = false): array {
        $user_id = (int) get_current_user_id();
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
        if ($identity['user_id'] > 0) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND guest_token = ''",
                $identity['user_id']
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = 0 AND guest_token = %s",
                $identity['guest_token']
            );
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function list_product_ids(array $actor): array {
        global $wpdb;

        $identity = self::normalize_actor_for_read($actor);
        if ($identity === null) return [];

        $table = self::table();
        if ($identity['user_id'] > 0) {
            $sql = $wpdb->prepare(
                "SELECT product_id FROM {$table} WHERE user_id = %d AND guest_token = '' ORDER BY updated_at DESC",
                $identity['user_id']
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT product_id FROM {$table} WHERE user_id = 0 AND guest_token = %s ORDER BY updated_at DESC",
                $identity['guest_token']
            );
        }

        $rows = $wpdb->get_col($sql);
        if (!is_array($rows)) return [];

        return array_map('intval', $rows);
    }

    public static function merge_guest_into_user(string $token, int $user_id): int {
        global $wpdb;

        $token = self::sanitize_token($token);
        $user_id = (int) $user_id;
        if ($token === '' || $user_id <= 0) return 0;

        $table = self::table();
        $now = current_time('mysql', true);

        $wpdb->query(
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

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = 0 AND guest_token = %s",
                $token
            )
        );

        if ($deleted === false) return 0;
        return (int) $deleted;
    }

    public static function is_valid_product(int $product_id): bool {
        if ($product_id <= 0) return false;

        $post = get_post($product_id);
        if (!$post instanceof \WP_Post) return false;
        if ($post->post_status === 'trash') return false;

        return in_array($post->post_type, ['product', 'product_variation'], true);
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
}
