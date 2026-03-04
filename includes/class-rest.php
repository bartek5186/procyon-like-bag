<?php
namespace Procyon\LikeBag;

if (!defined('ABSPATH')) exit;

class Rest {
    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('procyon-like-bag/v1', '/session', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'handle_session'],
            'permission_callback' => '__return_true',
            'args' => [
                'include_products' => ['type' => 'boolean', 'default' => false],
                'auto_merge' => ['type' => 'boolean', 'default' => true],
            ],
        ]);

        register_rest_route('procyon-like-bag/v1', '/items', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'handle_get_items'],
            'permission_callback' => '__return_true',
            'args' => [
                'include_products' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route('procyon-like-bag/v1', '/items', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_add_item'],
            'permission_callback' => '__return_true',
            'args' => [
                'product_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route('procyon-like-bag/v1', '/items/(?P<product_id>\d+)', [
            'methods'  => \WP_REST_Server::DELETABLE,
            'callback' => [__CLASS__, 'handle_remove_item'],
            'permission_callback' => '__return_true',
            'args' => [
                'product_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route('procyon-like-bag/v1', '/toggle', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_toggle_item'],
            'permission_callback' => '__return_true',
            'args' => [
                'product_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route('procyon-like-bag/v1', '/items', [
            'methods'  => \WP_REST_Server::DELETABLE,
            'callback' => [__CLASS__, 'handle_clear_items'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('procyon-like-bag/v1', '/count', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'handle_count'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('procyon-like-bag/v1', '/merge', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_merge'],
            'permission_callback' => [__CLASS__, 'can_merge'],
            'args' => [
                'token' => ['type' => 'string', 'required' => false],
            ],
        ]);
    }

    public static function can_merge(\WP_REST_Request $req): bool {
        unset($req);
        return is_user_logged_in();
    }

    public static function handle_session(\WP_REST_Request $req) {
        $include_products = (bool) $req->get_param('include_products');
        $auto_merge = (bool) $req->get_param('auto_merge');

        $actor = Store::resolve_actor($req, true);
        $merged = 0;

        if ((int) $actor['user_id'] > 0 && $auto_merge && $actor['guest_token'] !== '') {
            $merge = Store::merge_guest_into_user($actor['guest_token'], (int) $actor['user_id']);
            if (is_wp_error($merge)) return $merge;

            $merged = (int) ($merge['merged_new'] ?? 0);
            Store::forget_guest_cookie();
        }

        $payload = self::build_bag_payload($actor, $include_products);
        $payload['merged_from_guest'] = $merged;

        return self::respond($payload, $actor);
    }

    public static function handle_get_items(\WP_REST_Request $req) {
        $include_products = (bool) $req->get_param('include_products');
        $actor = Store::resolve_actor($req, false);

        $payload = self::build_bag_payload($actor, $include_products);
        return self::respond($payload, $actor);
    }

    public static function handle_add_item(\WP_REST_Request $req) {
        $product_id = (int) $req->get_param('product_id');
        if ($product_id <= 0) {
            return new \WP_Error('invalid_product_id', 'product_id must be a positive integer.', ['status' => 400]);
        }

        $actor = Store::resolve_actor($req, true);
        $added = Store::add_product($actor, $product_id);
        if (is_wp_error($added)) return $added;

        $payload = self::build_bag_payload($actor, false);
        $payload['action'] = 'added';
        $payload['product_id'] = $product_id;

        return self::respond($payload, $actor);
    }

    public static function handle_remove_item(\WP_REST_Request $req) {
        $product_id = (int) $req->get_param('product_id');
        if ($product_id <= 0) {
            return new \WP_Error('invalid_product_id', 'product_id must be a positive integer.', ['status' => 400]);
        }

        $actor = Store::resolve_actor($req, false);
        $removed = Store::remove_product($actor, $product_id);
        if (is_wp_error($removed)) return $removed;

        $payload = self::build_bag_payload($actor, false);
        $payload['action'] = $removed ? 'removed' : 'noop';
        $payload['product_id'] = $product_id;

        return self::respond($payload, $actor);
    }

    public static function handle_toggle_item(\WP_REST_Request $req) {
        $product_id = (int) $req->get_param('product_id');
        if ($product_id <= 0) {
            return new \WP_Error('invalid_product_id', 'product_id must be a positive integer.', ['status' => 400]);
        }

        $actor = Store::resolve_actor($req, true);
        $action = Store::toggle_product($actor, $product_id);
        if (is_wp_error($action)) return $action;

        $payload = self::build_bag_payload($actor, false);
        $payload['action'] = (string) $action;
        $payload['product_id'] = $product_id;

        return self::respond($payload, $actor);
    }

    public static function handle_clear_items(\WP_REST_Request $req) {
        $actor = Store::resolve_actor($req, false);
        $cleared = Store::clear_actor($actor);
        if (is_wp_error($cleared)) return $cleared;

        $payload = self::build_bag_payload($actor, false);
        $payload['action'] = 'cleared';
        $payload['cleared_count'] = (int) $cleared;

        return self::respond($payload, $actor);
    }

    public static function handle_count(\WP_REST_Request $req) {
        $actor = Store::resolve_actor($req, false);
        $count = Store::count_products($actor);

        $payload = [
            'source' => $actor['source'],
            'user_id' => (int) $actor['user_id'],
            'token' => ((int) $actor['user_id'] > 0) ? null : (($actor['guest_token'] !== '') ? $actor['guest_token'] : null),
            'count' => $count,
        ];

        return self::respond($payload, $actor);
    }

    public static function handle_merge(\WP_REST_Request $req) {
        $actor = Store::resolve_actor($req, false);
        $user_id = (int) $actor['user_id'];
        if ($user_id <= 0) {
            return new \WP_Error('auth_required', 'Log in to merge guest like bag.', ['status' => 401]);
        }

        $token = Store::sanitize_token((string) $req->get_param('token'));
        if ($token === '') {
            $token = Store::sanitize_token($actor['guest_token'] ?? '');
        }
        if ($token === '') {
            return new \WP_Error('missing_guest_token', 'Guest token is required for merge.', ['status' => 400]);
        }

        $merged = Store::merge_guest_into_user($token, $user_id);
        if (is_wp_error($merged)) return $merged;
        Store::forget_guest_cookie();

        $payload = self::build_bag_payload($actor, false);
        $payload['action'] = 'merged';
        $payload['merged_from_guest'] = (int) ($merged['merged_new'] ?? 0);
        $payload['merged_guest_rows'] = (int) ($merged['guest_rows'] ?? 0);
        $payload['deleted_guest_rows'] = (int) ($merged['deleted_guest_rows'] ?? 0);

        return self::respond($payload, $actor);
    }

    private static function build_bag_payload(array $actor, bool $include_products): array {
        $ids = Store::list_product_ids($actor);

        $payload = [
            'source' => $actor['source'],
            'user_id' => (int) $actor['user_id'],
            'token' => ((int) $actor['user_id'] > 0) ? null : (($actor['guest_token'] !== '') ? $actor['guest_token'] : null),
            'count' => count($ids),
            'product_ids' => $ids,
        ];

        if ($include_products) {
            $payload['products'] = self::hydrate_products($ids);
        }

        return $payload;
    }

    private static function hydrate_products(array $ids): array {
        $products = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            if (!Store::is_valid_product($id)) continue;

            if (function_exists('wc_get_product')) {
                $product = wc_get_product($id);
                if (!$product) continue;

                $products[] = [
                    'id' => $id,
                    'name' => $product->get_name(),
                    'slug' => $product->get_slug(),
                    'type' => $product->get_type(),
                    'permalink' => get_permalink($id),
                    'image' => wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail'),
                    'price' => $product->get_price(),
                    'price_html' => $product->get_price_html(),
                    'in_stock' => $product->is_in_stock(),
                ];
                continue;
            }

            $post = get_post($id);
            if (!$post instanceof \WP_Post) continue;
            if (!in_array($post->post_type, ['product', 'product_variation'], true)) continue;

            $products[] = [
                'id' => $id,
                'name' => get_the_title($id),
                'slug' => $post->post_name,
                'type' => $post->post_type,
                'permalink' => get_permalink($id),
                'image' => null,
                'price' => null,
                'price_html' => null,
                'in_stock' => null,
            ];
        }

        return $products;
    }

    private static function respond(array $payload, array $actor): \WP_REST_Response {
        $response = new \WP_REST_Response($payload);
        $response->set_status(200);

        if ((int) ($actor['user_id'] ?? 0) <= 0) {
            $token = Store::sanitize_token($actor['guest_token'] ?? '');
            if ($token !== '') {
                Store::persist_guest_token($token);
                $response->header('X-Procyon-Like-Bag-Token', $token);
                $response->header('Access-Control-Expose-Headers', 'X-Procyon-Like-Bag-Token');
            }
        }

        return $response;
    }
}
