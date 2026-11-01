<?php

namespace App\Support;

/**
 * The game's public address while `make public` has a Cloudflare tunnel running. The Makefile writes
 * it to storage/app/public_url when the tunnel comes up and deletes the file on `make public-stop`.
 * Read on each call rather than cached in config, because the tunnel address changes every run.
 */
class PublicUrl
{
    public static function path(): string
    {
        return config('bombbusters.public_url_file') ?? storage_path('app/public_url');
    }

    public static function current(): ?string
    {
        $url = is_file(self::path()) ? trim((string) file_get_contents(self::path())) : '';

        return preg_match('~^https://[a-z0-9.-]+$~i', $url) ? $url : null;
    }
}
