<?php

/**
 * Shared WordPress runtime + HTTP transport stubs for unit/contract tests.
 * Loaded from tests/bootstrap.php so every WordPress test file sees the same
 * helpers and globals (Admin lifecycle + flag-evaluation contracts).
 *
 * Options:        $GLOBALS['toggly_wordpress_admin_options']
 * Actions:        $GLOBALS['toggly_wordpress_admin_actions'] (flat list)
 *                 $GLOBALS['toggly_wp_actions'][$hook] (keyed for do_action)
 * Shortcodes:     $GLOBALS['toggly_wordpress_admin_shortcodes']
 * Transients:     $GLOBALS['toggly_wordpress_cache_values']
 * Cron:           $GLOBALS['toggly_wordpress_admin_scheduled']
 * HTTP override:  $GLOBALS['toggly_wordpress_http_handler'] or
 *                 $GLOBALS['toggly_wordpress_http_response']
 */

namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            /** @param mixed $message */
            public function __construct(private $message = '')
            {
            }

            public function get_error_message(): string
            {
                return (string) $this->message;
            }
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $option, mixed $default = false): mixed
        {
            return $GLOBALS['toggly_wordpress_admin_options'][$option] ?? $default;
        }
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $entry = compact('hook', 'callback', 'priority', 'acceptedArgs');
            $GLOBALS['toggly_wordpress_admin_actions'][] = $entry;
            $GLOBALS['toggly_wp_actions'][$hook][] = $entry;
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $entry = compact('hook', 'callback', 'priority', 'acceptedArgs');
            $GLOBALS['toggly_wordpress_admin_filters'][] = $entry;
            $GLOBALS['toggly_wp_filters'][$hook][] = $entry;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$args): void
        {
            foreach ($GLOBALS['toggly_wp_actions'][$hook] ?? [] as $entry) {
                ($entry['callback'])(...array_slice($args, 0, $entry['acceptedArgs']));
            }
        }
    }

    if (!function_exists('add_options_page')) {
        function add_options_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, mixed $callback): void
        {
            $GLOBALS['toggly_wordpress_admin_options_page'] = compact('pageTitle', 'menuTitle', 'capability', 'menuSlug', 'callback');
        }
    }

    if (!function_exists('register_setting')) {
        function register_setting(string $optionGroup, string $optionName, mixed $sanitizeCallback): void
        {
            $GLOBALS['toggly_wordpress_admin_settings'][] = compact('optionGroup', 'optionName', 'sanitizeCallback');
        }
    }

    if (!function_exists('add_settings_section')) {
        function add_settings_section(string $id, string $title, mixed $callback, string $page): void
        {
            $GLOBALS['toggly_wordpress_admin_sections'][] = compact('id', 'title', 'callback', 'page');
        }
    }

    if (!function_exists('add_settings_field')) {
        function add_settings_field(string $id, string $title, mixed $callback, string $page, string $section): void
        {
            $GLOBALS['toggly_wordpress_admin_fields'][] = compact('id', 'title', 'callback', 'page', 'section');
        }
    }

    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return $GLOBALS['toggly_wordpress_admin_capabilities'][$capability] ?? false;
        }
    }

    if (!function_exists('settings_fields')) {
        function settings_fields(string $optionGroup): void
        {
            $GLOBALS['toggly_wordpress_admin_rendered'][] = ['settings_fields', $optionGroup];
        }
    }

    if (!function_exists('do_settings_sections')) {
        function do_settings_sections(string $page): void
        {
            $GLOBALS['toggly_wordpress_admin_rendered'][] = ['do_settings_sections', $page];
        }
    }

    if (!function_exists('submit_button')) {
        function submit_button(): void
        {
            $GLOBALS['toggly_wordpress_admin_rendered'][] = ['submit_button'];
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $value): string
        {
            return trim(strip_tags($value));
        }
    }

    if (!function_exists('esc_url_raw')) {
        function esc_url_raw(string $value): string
        {
            return (string) filter_var($value, FILTER_SANITIZE_URL);
        }
    }

    if (!function_exists('esc_attr')) {
        function esc_attr(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('checked')) {
        function checked(mixed $checked, mixed $current = true): string
        {
            $result = $checked === $current ? 'checked="checked"' : '';
            echo $result;

            return $result;
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient(string $key): mixed
        {
            return $GLOBALS['toggly_wordpress_cache_values'][$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient(string $key, mixed $value, int $expiration = 0): bool
        {
            $GLOBALS['toggly_wordpress_cache_adapter_calls'][] = [
                'key' => $key,
                'value' => $value,
                'expiration' => $expiration,
            ];
            $result = $GLOBALS['toggly_wordpress_cache_set_results'][$key] ?? true;
            if ($result) {
                $GLOBALS['toggly_wordpress_cache_values'][$key] = $value;
            }

            return $result;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            $GLOBALS['toggly_wordpress_cache_delete_calls'][] = $key;
            $result = $GLOBALS['toggly_wordpress_cache_delete_results'][$key] ?? true;
            if ($result) {
                unset($GLOBALS['toggly_wordpress_cache_values'][$key]);
            }

            return $result;
        }
    }

    if (!function_exists('wp_get_current_user')) {
        function wp_get_current_user(): mixed
        {
            return $GLOBALS['toggly_wordpress_current_user'] ?? null;
        }
    }

    if (!function_exists('add_shortcode')) {
        function add_shortcode(string $tag, mixed $callback): void
        {
            $GLOBALS['toggly_wordpress_admin_shortcodes'][$tag] = $callback;
        }
    }

    if (!function_exists('shortcode_atts')) {
        function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array
        {
            return array_merge($pairs, $atts);
        }
    }

    if (!function_exists('do_shortcode')) {
        function do_shortcode(string $content): string
        {
            return (string) preg_replace_callback(
                '/\[(\w+)([^\]]*)\](.*?)\[\/\1\]/s',
                static function (array $matches): string {
                    $tag = $matches[1];
                    $attrString = trim($matches[2]);
                    $inner = $matches[3];
                    $callback = $GLOBALS['toggly_wordpress_admin_shortcodes'][$tag] ?? null;
                    if (!is_callable($callback)) {
                        return $matches[0];
                    }

                    $atts = [];
                    if ($attrString !== '' && preg_match_all('/(\w+)="([^"]*)"/', $attrString, $attrMatches, PREG_SET_ORDER)) {
                        foreach ($attrMatches as $attr) {
                            $atts[$attr[1]] = $attr[2];
                        }
                    }

                    return (string) $callback($atts, $inner);
                },
                $content
            );
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled(string $hook): int|false
        {
            return $GLOBALS['toggly_wordpress_admin_scheduled'][$hook] ?? false;
        }
    }

    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook): void
        {
            $GLOBALS['toggly_wordpress_admin_scheduled'][$hook] = $timestamp;
            $GLOBALS['toggly_wordpress_admin_schedule_calls'][] = compact('timestamp', 'recurrence', 'hook');
        }
    }
}

namespace Toggly\WordPress\Http {
    if (!function_exists(__NAMESPACE__ . '\\wp_get_current_user')) {
        function wp_get_current_user(): mixed
        {
            return $GLOBALS['toggly_wordpress_current_user'] ?? null;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_request')) {
        function wp_remote_request(string $url, array $args): mixed
        {
            $GLOBALS['toggly_wordpress_http_request'] = compact('url', 'args');

            $handler = $GLOBALS['toggly_wordpress_http_handler'] ?? null;
            if (is_callable($handler)) {
                return $handler($url, $args);
            }

            return $GLOBALS['toggly_wordpress_http_response'] ?? [
                'response' => ['code' => 500, 'message' => 'No mock response'],
                'headers' => [],
                'body' => '',
            ];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        function is_wp_error(mixed $response): bool
        {
            return $response instanceof \WP_Error;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code(array $response): int
        {
            return (int) ($response['response']['code'] ?? 0);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_response_message')) {
        function wp_remote_retrieve_response_message(array $response): string
        {
            return (string) ($response['response']['message'] ?? '');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(array $response): string
        {
            return (string) ($response['body'] ?? '');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_headers')) {
        function wp_remote_retrieve_headers(array $response): array
        {
            $headers = $response['headers'] ?? [];

            return is_array($headers) ? $headers : [];
        }
    }
}
