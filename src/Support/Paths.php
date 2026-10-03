<?php

namespace AgenticActions\Support;

/**
 * Resolves configured paths against the app's base path.
 *
 * @internal
 */
final class Paths
{
    /**
     * An absolute path as is (/…, or a Windows drive), otherwise base_path($path).
     */
    public static function resolve(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
