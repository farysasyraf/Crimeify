<?php

if (! function_exists('versioned_asset')) {
    /**
     * The address of a file in public/ with the time it last changed added, like /js/sidebar.js?v=1790494171.
     * Every change gives the file a new address, so browsers fetch it again instead of keeping an old copy,
     * which they otherwise do for hours, since the web server doesn't say how long to keep it.
     */
    function versioned_asset(string $path): string
    {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
