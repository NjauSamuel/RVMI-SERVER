<?php

namespace App\Support;

/**
 * The full set of permissions the app understands. Roles are a free
 * combination of these (see RolePermissionSeeder for the built-in roles).
 */
final class Permissions
{
    public const UPLOAD_MEDIA = 'upload-media';

    public const APPROVE_MEDIA = 'approve-media';

    public const MANAGE_EVENTS = 'manage-events';

    public const MANAGE_PEOPLE = 'manage-people';

    public const VIEW_PRIVATE_MEDIA = 'view-private-media';

    public const COMMENT = 'comment';

    public const MANAGE_INVENTORY = 'manage-inventory';

    public const MANAGE_USERS = 'manage-users';

    public const DELETE_RECORDS = 'delete-records';

    public const MANAGE_ROLES = 'manage-roles';

    public const MANAGE_SETTINGS = 'manage-settings';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::UPLOAD_MEDIA,
            self::APPROVE_MEDIA,
            self::MANAGE_EVENTS,
            self::MANAGE_PEOPLE,
            self::VIEW_PRIVATE_MEDIA,
            self::COMMENT,
            self::MANAGE_INVENTORY,
            self::MANAGE_USERS,
            self::DELETE_RECORDS,
            self::MANAGE_ROLES,
            self::MANAGE_SETTINGS,
        ];
    }

    /**
     * Permissions that unlock app nav items which must be entirely hidden
     * from members (not just disabled) when absent.
     *
     * @return array<int, string>
     */
    public static function navGated(): array
    {
        return [
            self::UPLOAD_MEDIA,
            self::APPROVE_MEDIA,
            self::MANAGE_INVENTORY,
            self::MANAGE_USERS,
            self::MANAGE_ROLES,
            self::MANAGE_SETTINGS,
        ];
    }
}
