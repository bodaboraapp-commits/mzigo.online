<?php
/**
 * Minimal .env loader.
 *
 * Loads KEY=VALUE pairs from the project root .env without overriding values
 * already supplied by the hosting environment. This keeps Docker/system env
 * variables authoritative while allowing local XAMPP and cPanel deployments to
 * use a familiar .env file.
 */
declare(strict_types=1);

function mzigo_load_env(string $path): void
{
    static $loaded = [];
    $key = realpath($path) ?: $path;
    if (isset($loaded[$key])) { return; }
    $loaded[$key] = true;
    if (!is_file($path) || !is_readable($path)) { return; }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) { return; }

    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('/^\xEF\xBB\xBF+/', '', $line) ?? $line;
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) { continue; }
        if (str_starts_with($line, 'export ')) { $line = ltrim(substr($line, 7)); }
        $eq = strpos($line, '=');
        if ($eq === false) { continue; }
        $name = trim(substr($line, 0, $eq));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) { continue; }

        // Real environment variables and earlier duplicate keys win.
        if (getenv($name) !== false) { continue; }

        $value = trim(substr($line, $eq + 1));
        $first = $value !== '' ? $value[0] : '';
        if ($first === '"' || $first === "'") {
            $quote = $first;
            $end = strrpos($value, $quote);
            if ($end !== false && $end > 0) {
                $value = substr($value, 1, $end - 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $value);
                } else {
                    $value = str_replace("\\'", "'", $value);
                }
            }
        } else {
            // Strip unquoted inline comments (e.g. KEY=value # note).
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            $value = trim($value);
        }

        putenv($name . '=' . $value);
        if (!array_key_exists($name, $_ENV)) { $_ENV[$name] = $value; }
        if (!array_key_exists($name, $_SERVER)) { $_SERVER[$name] = $value; }
    }
}
