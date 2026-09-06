<?php
/**
 * Registers the poll post type and meta schema.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\PostType;

/**
 * Defines the poll CPT and validates editor-controlled meta.
 */
final class PollPostType
{
    public const POST_TYPE = 'zw_poll';
    public const CAP_SINGULAR = 'zw_poll';
    public const CAP_PLURAL = 'zw_polls';

    public const META_PREFIX = '_zw_poll_';
    public const META_OPTIONS = self::META_PREFIX . 'options';
    public const META_STATUS = self::META_PREFIX . 'status';
    public const META_TOTAL_VISIBILITY = self::META_PREFIX . 'total_visibility';
    public const TOTAL_VISIBILITY_DEFAULT = 'default';
    public const TOTAL_VISIBILITY_HIDE = 'hide';
    public const TOTAL_VISIBILITY_SHOW = 'show';
    public const META_AGGREGATE = self::META_PREFIX . 'aggregate';
    public const META_VOTE_EPOCH = self::META_PREFIX . 'vote_epoch';

    // Authoritative editorial limits; the poll edit form mirrors these.
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 10;
    public const MAX_QUESTION_LEN = 200;
    public const MAX_OPTION_LEN = 280;

    /**
     * Registers post-type and meta hooks.
     */
    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('init', [$this, 'registerMeta']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'seedAggregateMeta']);
        add_filter('wp_insert_post_data', [$this, 'capTitleLength']);
    }

    /**
     * Registers the private editorial poll post type.
     */
    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Polls', 'zw-poll'),
                'singular_name' => __('Poll', 'zw-poll'),
                'add_new' => __('Nieuwe poll', 'zw-poll'),
                'add_new_item' => __('Nieuwe poll toevoegen', 'zw-poll'),
                'edit_item' => __('Poll bewerken', 'zw-poll'),
                'new_item' => __('Nieuwe poll', 'zw-poll'),
                'view_item' => __('Poll bekijken', 'zw-poll'),
                'search_items' => __('Polls zoeken', 'zw-poll'),
                'not_found' => __('Geen polls gevonden', 'zw-poll'),
                'not_found_in_trash' => __('Geen polls gevonden in de prullenbak', 'zw-poll'),
                'menu_name' => __('Polls', 'zw-poll'),
                'all_items' => __('Alle polls', 'zw-poll'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'rest_base' => 'zw-polls',
            // REST meta needs custom-fields; PollMetaBoxes hides the raw box.
            // show_in_rest stays on for the classic-editor poll picker and the status toggle.
            'supports' => ['title', 'custom-fields'],
            'has_archive' => false,
            'rewrite' => false,
            'menu_icon' => 'dashicons-editor-help',
            'menu_position' => 26,
            'capability_type' => [self::CAP_SINGULAR, self::CAP_PLURAL],
            'map_meta_cap' => true,
        ]);
    }

    /**
     * Registers REST-enabled poll meta fields.
     */
    public function registerMeta(): void
    {
        $auth = static fn (bool $allowed, string $meta_key, int $post_id): bool => current_user_can('edit_post', $post_id);

        register_post_meta(self::POST_TYPE, self::META_OPTIONS, [
            'type' => 'array',
            'single' => true,
            'default' => [],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'sanitize_callback' => [$this, 'sanitizeOptions'],
            'auth_callback' => $auth,
        ]);

        register_post_meta(self::POST_TYPE, self::META_STATUS, [
            'type' => 'string',
            'single' => true,
            'default' => 'open',
            'show_in_rest' => [
                'schema' => ['type' => 'string', 'enum' => ['open', 'closed']],
            ],
            'sanitize_callback' => static fn ($v): string => in_array($v, ['open', 'closed'], true) ? (string) $v : 'open',
            'auth_callback' => $auth,
        ]);

        register_post_meta(self::POST_TYPE, self::META_TOTAL_VISIBILITY, [
            'type' => 'string',
            'single' => true,
            'default' => self::TOTAL_VISIBILITY_DEFAULT,
            'show_in_rest' => [
                'schema' => [
                    'type' => 'string',
                    'enum' => self::totalVisibilityValues(),
                ],
            ],
            'sanitize_callback' => [self::class, 'sanitizeTotalVisibility'],
            'auth_callback' => $auth,
        ]);

        register_post_meta(self::POST_TYPE, self::META_AGGREGATE, [
            'type' => 'object',
            'single' => true,
            // No default: a null default fails core's type check and makes
            // register_meta return false before this object meta reaches the
            // registry. Readers handle missing meta themselves.
            'auth_callback' => $auth,
        ]);

        register_post_meta(self::POST_TYPE, self::META_VOTE_EPOCH, [
            'type' => 'integer',
            'single' => true,
            'default' => 0,
            'auth_callback' => $auth,
        ]);
    }

    /**
     * Returns the poll status, defaulting missing or blank meta to open.
     *
     * @param int $poll_id Poll post ID.
     */
    public static function status(int $poll_id): string
    {
        return (string) (get_post_meta($poll_id, self::META_STATUS, true) ?: 'open');
    }

    /**
     * Returns the supported per-poll total visibility values.
     *
     * @return list<string>
     */
    public static function totalVisibilityValues(): array
    {
        return [
            self::TOTAL_VISIBILITY_DEFAULT,
            self::TOTAL_VISIBILITY_HIDE,
            self::TOTAL_VISIBILITY_SHOW,
        ];
    }

    /**
     * Sanitizes a total visibility value to the default policy.
     *
     * @param mixed $value Raw meta value.
     */
    public static function sanitizeTotalVisibility(mixed $value): string
    {
        return is_string($value) && in_array($value, self::totalVisibilityValues(), true)
            ? $value
            : self::TOTAL_VISIBILITY_DEFAULT;
    }

    /**
     * Returns the effective per-poll total visibility policy.
     *
     * @param int $poll_id Poll post ID.
     */
    public static function totalVisibility(int $poll_id): string
    {
        return self::sanitizeTotalVisibility(get_post_meta($poll_id, self::META_TOTAL_VISIBILITY, true));
    }

    /**
     * Returns valid option rows for a poll.
     *
     * Re-validates at every read boundary because stored meta may predate
     * sanitizeOptions(); callers only receive valid rows.
     *
     * @param int $poll_id Poll post ID.
     * @return array<int, array{id: string, label: string}>
     */
    public static function options(int $poll_id): array
    {
        $raw = get_post_meta($poll_id, self::META_OPTIONS, true);
        if (!is_array($raw)) {
            return [];
        }

        $options = [];
        foreach ($raw as $option) {
            if (!is_array($option) || !is_string($option['id'] ?? null) || !is_string($option['label'] ?? null)) {
                continue;
            }

            $id = trim($option['id']);
            $label = trim($option['label']);
            if ($id === '' || $label === '') {
                continue;
            }

            $options[] = ['id' => $id, 'label' => $label];
        }

        if (count($raw) > count($options)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Corrupt stored poll options should be visible in server logs.
            error_log(sprintf(
                'zw-poll: poll %d dropped %d invalid stored option row(s); %d valid option row(s) remain.',
                $poll_id,
                count($raw) - count($options),
                count($options)
            ));
        }

        return $options;
    }

    /**
     * Checks whether a question and options make a poll renderable.
     *
     * Single displayability predicate: the frontend gate (PollRenderer) and
     * the classic form's incomplete-poll warning must never drift apart.
     *
     * @param string                                       $question Poll question.
     * @param array<int, array{id: string, label: string}> $options  Valid option rows.
     */
    public static function isComplete(string $question, array $options): bool
    {
        return trim($question) !== '' && count($options) >= self::MIN_OPTIONS;
    }

    /**
     * Sanitizes submitted option rows.
     *
     * @param mixed $value Raw options meta value.
     * @return array<int, array{id: string, label: string}>
     */
    public function sanitizeOptions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $used_ids = [];
        foreach ($value as $opt) {
            if (!is_array($opt) || !isset($opt['label'])) {
                continue;
            }
            $label = mb_substr(trim(sanitize_text_field((string) $opt['label'])), 0, self::MAX_OPTION_LEN);
            if ($label === '') {
                continue;
            }
            $id = isset($opt['id']) && is_string($opt['id']) && wp_is_uuid($opt['id'], 4) && !isset($used_ids[$opt['id']])
                ? $opt['id']
                : wp_generate_uuid4();
            $used_ids[$id] = true;
            $out[] = ['id' => $id, 'label' => $label];
            if (count($out) >= self::MAX_OPTIONS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Caps poll titles on every save path.
     *
     * The title is the reader-facing question. Core's own title handling is
     * allowed to keep editorial text intact, including literal comparison
     * signs; this filter only enforces the plugin's length limit across quick
     * edit, REST, CLI, and classic-editor saves.
     *
     * @param array<string, mixed> $data Slashed post data about to be saved.
     * @return array<string, mixed>
     */
    public function capTitleLength(array $data): array
    {
        if (($data['post_type'] ?? '') !== self::POST_TYPE) {
            return $data;
        }

        $title = (string) wp_unslash((string) ($data['post_title'] ?? ''));
        if (mb_strlen($title) > self::MAX_QUESTION_LEN) {
            $data['post_title'] = wp_slash(mb_substr($title, 0, self::MAX_QUESTION_LEN));
        }

        return $data;
    }

    /**
     * Seeds an aggregate row so first votes can use compare-and-swap updates.
     *
     * @param int $post_id Poll post ID.
     */
    public function seedAggregateMeta(int $post_id): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        add_post_meta($post_id, self::META_AGGREGATE, [
            'counts' => [],
            'total' => 0,
            'updated_at' => '',
        ], true);
    }
}
