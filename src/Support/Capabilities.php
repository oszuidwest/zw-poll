<?php
/**
 * Manages poll capabilities.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Support;

use ZuidWest\Poll\PostType\PollPostType;

/**
 * Grants and revokes primitive capabilities for poll editors.
 */
final class Capabilities
{
    public const PRIMITIVE_CAPS = [
        'edit_' . PollPostType::CAP_PLURAL,
        'edit_others_' . PollPostType::CAP_PLURAL,
        'edit_private_' . PollPostType::CAP_PLURAL,
        'edit_published_' . PollPostType::CAP_PLURAL,
        'publish_' . PollPostType::CAP_PLURAL,
        'read_private_' . PollPostType::CAP_PLURAL,
        'delete_' . PollPostType::CAP_PLURAL,
        'delete_others_' . PollPostType::CAP_PLURAL,
        'delete_private_' . PollPostType::CAP_PLURAL,
        'delete_published_' . PollPostType::CAP_PLURAL,
        'manage_' . PollPostType::CAP_PLURAL,
    ];

    public const ROLES = ['administrator', 'editor'];

    /** Grants poll capabilities to default editorial roles. */
    public static function grantToDefaultRoles(): void
    {
        foreach (self::ROLES as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach (self::PRIMITIVE_CAPS as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    /** Revokes poll capabilities from default editorial roles. */
    public static function revokeFromDefaultRoles(): void
    {
        foreach (self::ROLES as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach (self::PRIMITIVE_CAPS as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
}
