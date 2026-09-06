<?php

declare(strict_types=1);

namespace ZuidWest\Poll\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeZone;
use ZuidWest\Poll\PostType\PollPostType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PollPostTypeTest extends TestCase
{
    private PollPostType $sut;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_generate_uuid4')->justReturn('11111111-2222-4333-8444-555555555555');
        $this->sut = new PollPostType();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function post_type_keeps_custom_fields_support_for_rest_meta(): void
    {
        $registered_post_type = '';
        $registered_args = [];
        Functions\when('__')->returnArg();
        Functions\when('register_post_type')->alias(
            static function (string $post_type, array $args) use (&$registered_post_type, &$registered_args): void {
                $registered_post_type = $post_type;
                $registered_args = $args;
            }
        );

        $this->sut->registerPostType();

        $this->assertSame(PollPostType::POST_TYPE, $registered_post_type);
        $this->assertSame(['title', 'custom-fields'], $registered_args['supports']);
    }

    #[Test]
    public function register_meta_exposes_total_visibility_as_an_enum(): void
    {
        $registered = [];
        Functions\when('register_post_meta')->alias(
            static function (string $post_type, string $meta_key, array $args) use (&$registered): void {
                $registered[$meta_key] = $args;
            }
        );

        $this->sut->registerMeta();

        $args = $registered[PollPostType::META_TOTAL_VISIBILITY];
        $this->assertSame('string', $args['type']);
        $this->assertSame(PollPostType::TOTAL_VISIBILITY_DEFAULT, $args['default']);
        $this->assertSame(PollPostType::totalVisibilityValues(), $args['show_in_rest']['schema']['enum']);
        $this->assertSame([PollPostType::class, 'sanitizeTotalVisibility'], $args['sanitize_callback']);
    }

    #[Test]
    public function option_images_are_non_negative_rest_integers(): void
    {
        $registered = [];
        Functions\when('register_post_meta')->alias(
            static function (string $post_type, string $meta_key, array $args) use (&$registered): void {
                $registered[$meta_key] = $args;
            }
        );

        $this->sut->registerMeta();

        $schema = $registered[PollPostType::META_OPTIONS]['show_in_rest']['schema'];
        $image_schema = $schema['items']['properties']['imageId'];
        $this->assertSame('integer', $image_schema['type']);
        $this->assertSame(0, $image_schema['minimum']);
    }

    #[Test]
    public function option_image_ids_normalize_without_turning_negatives_positive(): void
    {
        $this->assertSame(123, PollPostType::normalizeImageId('123'));
        $this->assertSame(0, PollPostType::normalizeImageId('-123'));
        $this->assertSame(0, PollPostType::normalizeImageId('invalid'));
        $this->assertSame(0, PollPostType::normalizeImageId('123px'));
        $this->assertSame(0, PollPostType::normalizeImageId(12.3));
        $this->assertSame(0, PollPostType::normalizeImageId(['123']));

        Functions\when('get_post_meta')->justReturn([
            ['id' => 'a', 'label' => 'A', 'imageId' => '123'],
            ['id' => 'b', 'label' => 'B'],
        ]);
        $this->assertSame([
            ['id' => 'a', 'label' => 'A', 'imageId' => 123],
            ['id' => 'b', 'label' => 'B', 'imageId' => 0],
        ], PollPostType::options(42));
    }

    #[Test]
    public function complete_images_requires_a_live_image_for_every_option(): void
    {
        Functions\when('_prime_post_caches')->justReturn(null);
        Functions\when('wp_attachment_is_image')->alias(
            static fn (int $attachment_id): bool => in_array($attachment_id, [101, 102], true)
        );
        $complete = [
            ['id' => 'a', 'label' => 'A', 'imageId' => 101],
            ['id' => 'b', 'label' => 'B', 'imageId' => 102],
        ];

        $this->assertTrue(PollPostType::hasCompleteImages($complete));
        $this->assertTrue(PollPostType::hasAnyImages($complete));
        $this->assertFalse(PollPostType::hasCompleteImages([$complete[0], ['id' => 'b', 'label' => 'B']]));
        $this->assertFalse(PollPostType::hasCompleteImages([$complete[0], ['id' => 'b', 'label' => 'B', 'imageId' => 999]]));
        $this->assertFalse(PollPostType::hasCompleteImages([]));
    }

    #[Test]
    public function register_meta_exposes_the_closing_deadline(): void
    {
        $registered = [];
        Functions\when('register_post_meta')->alias(
            static function (string $post_type, string $meta_key, array $args) use (&$registered): void {
                $registered[$meta_key] = $args;
            }
        );

        $this->sut->registerMeta();

        $args = $registered[PollPostType::META_CLOSES_AT];
        $this->assertSame('integer', $args['type']);
        $this->assertSame(0, $args['default']);
        $this->assertTrue($args['show_in_rest']);
        $this->assertSame('absint', $args['sanitize_callback']);
    }

    #[Test]
    public function deadline_helpers_use_the_site_timezone_and_reject_invalid_dates(): void
    {
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Amsterdam'));
        Functions\when('get_post_meta')->justReturn('1784809800');
        Functions\when('get_option')->alias(
            static fn (string $key): string => $key === 'date_format' ? 'd-m-Y' : 'H:i'
        );
        Functions\when('wp_date')->alias(
            static fn (string $format, int $timestamp): string => gmdate($format, $timestamp)
        );

        $this->assertSame(1784809800, PollPostType::closesAt(42));
        $this->assertSame(1767223800, PollPostType::parseDeadline('2026-01-01T00:30'));
        $this->assertNull(PollPostType::parseDeadline('2026-02-30T12:00'));
        $this->assertSame('23-07-2026 12:30', PollPostType::formatClosesAt(1784809800));
        $this->assertSame('', PollPostType::formatClosesAt(0));
    }

    #[Test]
    public function total_visibility_uses_stored_meta_and_defaults_invalid_values(): void
    {
        Functions\when('get_post_meta')->justReturn('hide');
        $this->assertSame('hide', PollPostType::totalVisibility(42));

        Functions\when('get_post_meta')->justReturn('corrupt');
        $this->assertSame('default', PollPostType::totalVisibility(42));
    }

    #[Test]
    public function register_meta_gives_aggregate_no_default(): void
    {
        // A null default fails core's type check and keeps the object meta out
        // of the registry; readers handle missing meta.
        $registered = [];
        Functions\when('register_post_meta')->alias(
            static function (string $post_type, string $meta_key, array $args) use (&$registered): void {
                $registered[$meta_key] = $args;
            }
        );

        $this->sut->registerMeta();

        $this->assertArrayNotHasKey('default', $registered[PollPostType::META_AGGREGATE]);
    }

    #[Test]
    public function registered_meta_auth_uses_object_level_edit_permission(): void
    {
        $callbacks = [];
        Functions\when('register_post_meta')->alias(
            static function (string $post_type, string $meta_key, array $args) use (&$callbacks): void {
                $callbacks[$meta_key] = $args['auth_callback'];
            }
        );
        Functions\when('current_user_can')->alias(
            static fn (string $capability, int $post_id = 0): bool => $capability === 'edit_post' && $post_id === 123
        );

        $this->sut->registerMeta();

        $this->assertArrayHasKey(PollPostType::META_OPTIONS, $callbacks);
        $this->assertTrue($callbacks[PollPostType::META_OPTIONS](true, PollPostType::META_OPTIONS, 123, 7, 'edit_post_meta', []));
        $this->assertFalse($callbacks[PollPostType::META_OPTIONS](true, PollPostType::META_OPTIONS, 456, 7, 'edit_post_meta', []));
    }

    #[Test]
    public function sanitize_options_returns_empty_array_for_non_array_input(): void
    {
        $this->assertSame([], $this->sut->sanitizeOptions('not-an-array'));
        $this->assertSame([], $this->sut->sanitizeOptions(null));
    }

    #[Test]
    public function sanitize_options_keeps_valid_uuid_v4(): void
    {
        $input = [
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => 'Yes'],
        ];
        $out = $this->sut->sanitizeOptions($input);
        $this->assertCount(1, $out);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', $out[0]['id']);
        $this->assertSame('Yes', $out[0]['label']);
    }

    #[Test]
    public function sanitize_options_regenerates_invalid_id(): void
    {
        $input = [['id' => 'not-a-uuid', 'label' => 'Yes']];
        $out = $this->sut->sanitizeOptions($input);
        // UUID generation is mocked to the fixture UUID.
        $this->assertSame('11111111-2222-4333-8444-555555555555', $out[0]['id']);
    }

    #[Test]
    public function sanitize_options_regenerates_uuid_v1(): void
    {
        // UUID v1 has the wrong version nibble for v4 validation.
        $input = [['id' => 'aaaaaaaa-bbbb-1ccc-9ddd-eeeeeeeeeeee', 'label' => 'Yes']];
        $out = $this->sut->sanitizeOptions($input);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $out[0]['id']);
    }

    #[Test]
    public function sanitize_options_drops_empty_labels(): void
    {
        $input = [
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => 'Yes'],
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeef', 'label' => ''],
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeef0', 'label' => '   '],
        ];
        $out = $this->sut->sanitizeOptions($input);

        $this->assertCount(1, $out);
        $this->assertSame('Yes', $out[0]['label']);
    }

    #[Test]
    public function sanitize_options_trims_labels(): void
    {
        $input = [
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => '  Yes  '],
        ];
        $out = $this->sut->sanitizeOptions($input);

        $this->assertSame('Yes', $out[0]['label']);
    }

    #[Test]
    public function sanitize_options_truncates_long_labels(): void
    {
        $input = [['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => str_repeat('x', 400)]];
        $out = $this->sut->sanitizeOptions($input);
        $this->assertSame(280, mb_strlen($out[0]['label']));
    }

    #[Test]
    public function sanitize_options_caps_at_max_options(): void
    {
        $input = [];
        for ($i = 0; $i < 15; $i++) {
            $input[] = [
                'id' => sprintf('aaaaaaaa-bbbb-4ccc-9ddd-%012d', $i),
                'label' => 'Option ' . $i,
            ];
        }
        $out = $this->sut->sanitizeOptions($input);
        $this->assertCount(10, $out);
    }

    #[Test]
    public function sanitize_options_keeps_scanning_until_max_valid_options(): void
    {
        $input = array_fill(0, 10, ['label' => '']);
        $input[] = ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => 'Real'];

        $out = $this->sut->sanitizeOptions($input);

        $this->assertCount(1, $out);
        $this->assertSame('Real', $out[0]['label']);
    }

    #[Test]
    public function sanitize_options_regenerates_duplicate_ids(): void
    {
        $input = [
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => 'First'],
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => 'Second'],
        ];

        $out = $this->sut->sanitizeOptions($input);

        $this->assertSame('aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', $out[0]['id']);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $out[1]['id']);
    }

    #[Test]
    public function sanitize_options_skips_entries_without_label(): void
    {
        $input = [
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee'],
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeef', 'label' => 'Real'],
        ];
        $out = $this->sut->sanitizeOptions($input);
        $this->assertCount(1, $out);
        $this->assertSame('Real', $out[0]['label']);
    }

    #[Test]
    public function sanitize_options_skips_non_string_labels(): void
    {
        $input = [
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee', 'label' => ['nested']],
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeef', 'label' => new \stdClass()],
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeef0', 'label' => 'Real'],
        ];

        $out = $this->sut->sanitizeOptions($input);

        $this->assertSame([
            ['id' => 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeef0', 'label' => 'Real', 'imageId' => 0],
        ], $out);
    }

    #[Test]
    public function seed_aggregate_meta_adds_empty_unique_cache_anchor(): void
    {
        $captured = [];
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('add_post_meta')->alias(
            static function (int $post_id, string $key, array $value, bool $unique) use (&$captured): int {
                $captured = [$post_id, $key, $value, $unique];

                return 1;
            }
        );

        $this->sut->seedAggregateMeta(42);

        $this->assertSame(42, $captured[0]);
        $this->assertSame(PollPostType::META_AGGREGATE, $captured[1]);
        $this->assertSame(['counts' => [], 'total' => 0, 'updated_at' => ''], $captured[2]);
        $this->assertTrue($captured[3]);
    }

    #[Test]
    public function seed_aggregate_meta_skips_revisions_and_autosaves(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(true);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\expect('add_post_meta')->never();

        $this->sut->seedAggregateMeta(42);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function register_hooks_the_title_length_cap(): void
    {
        $this->sut->register();

        $this->assertNotFalse(has_filter(
            'wp_insert_post_data',
            [$this->sut, 'capTitleLength']
        ));
    }

    #[Test]
    public function cap_title_length_truncates_overlong_poll_titles(): void
    {
        // wp_insert_post_data carries slashed values; the cap must measure
        // the unslashed title and hand back a slashed one.
        Functions\when('wp_unslash')->alias(static fn (string $value): string => stripslashes($value));
        Functions\when('wp_slash')->alias(static fn (string $value): string => addslashes($value));

        $data = $this->sut->capTitleLength([
            'post_type' => PollPostType::POST_TYPE,
            'post_title' => str_repeat('a', 300),
        ]);

        $this->assertSame(200, mb_strlen($data['post_title']));
    }

    #[Test]
    public function cap_title_length_preserves_short_titles_with_markup_and_comparison_signs(): void
    {
        Functions\when('wp_unslash')->alias(static fn (string $value): string => stripslashes($value));

        $data = $this->sut->capTitleLength([
            'post_type' => PollPostType::POST_TYPE,
            'post_title' => 'Moet <em>5 < x</em> en x > 10 blijven?',
        ]);

        $this->assertSame('Moet <em>5 < x</em> en x > 10 blijven?', $data['post_title']);
    }

    #[Test]
    public function cap_title_length_keeps_exact_boundary_untouched(): void
    {
        Functions\when('wp_unslash')->alias(static fn (string $value): string => stripslashes($value));

        $data = $this->sut->capTitleLength([
            'post_type' => PollPostType::POST_TYPE,
            'post_title' => str_repeat('a', PollPostType::MAX_QUESTION_LEN),
        ]);

        $this->assertSame(str_repeat('a', PollPostType::MAX_QUESTION_LEN), $data['post_title']);
    }

    #[Test]
    public function cap_title_length_truncates_multibyte_titles_by_character(): void
    {
        Functions\when('wp_unslash')->alias(static fn (string $value): string => stripslashes($value));
        Functions\when('wp_slash')->alias(static fn (string $value): string => addslashes($value));

        $data = $this->sut->capTitleLength([
            'post_type' => PollPostType::POST_TYPE,
            'post_title' => str_repeat('é', PollPostType::MAX_QUESTION_LEN + 1),
        ]);

        $this->assertSame(PollPostType::MAX_QUESTION_LEN, mb_strlen($data['post_title']));
        $this->assertSame(str_repeat('é', PollPostType::MAX_QUESTION_LEN), $data['post_title']);
    }

    #[Test]
    public function cap_title_length_ignores_other_post_types(): void
    {
        $data = $this->sut->capTitleLength([
            'post_type' => 'post',
            'post_title' => str_repeat('a', 300),
        ]);

        $this->assertSame(300, mb_strlen($data['post_title']));
    }
}
