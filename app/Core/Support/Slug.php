<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * v1's generateSlug(): lower case, punctuation removed, runs of spaces,
 * dashes and underscores become one underscore ("Sub Admin" -> "sub_admin").
 */
final class Slug
{
    public static function v1(string $value): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = (string) preg_replace('/[^\w\s-]/u', '', $slug);
        $slug = (string) preg_replace('/[\s_-]+/', '_', $slug);

        return trim($slug, '_');
    }
}
