<?php

namespace Rss\Vlog\Core\Http;

/**
 * Renders the viewer's HTML page, with its built script and styles inlined.
 *
 * Inlining means the page needs no asset routes or published files, so it
 * always matches the installed version of the package.
 */
final class UiPage
{
    private string $assetDirectory;

    /**
     * @param  string|null  $assetDirectory  The folder holding vlog.js and vlog.css. Defaults to the package's dist folder.
     */
    public function __construct(?string $assetDirectory = null)
    {
        $this->assetDirectory = $assetDirectory ?? dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'dist';
    }

    /**
     * Render the page.
     *
     * @param  string  $apiUrl  The URL the API routes start with, such as "/vlog/api".
     * @param  string|null  $nonce  A Content Security Policy nonce for the inline script and styles.
     * @param  array<string, scalar|null>  $settings  More settings for the interface, such as "canManage" and "csrfToken".
     */
    public function render(string $apiUrl, string $title = 'Vlog', ?string $nonce = null, array $settings = []): string
    {
        $script = $this->asset('vlog.js');
        $styles = $this->asset('vlog.css');
        $nonceAttribute = $nonce === null ? '' : ' nonce="'.$this->escape($nonce).'"';
        $escapedTitle = $this->escape($title);

        if ($script === null || $styles === null) {
            return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'.$escapedTitle.'</title></head>'
                .'<body><p>The Vlog interface has not been built. Run <code>npm run build</code> in the package folder.</p></body></html>';
        }

        $config = (string) json_encode(
            array_merge($settings, ['apiUrl' => $apiUrl, 'title' => $title]),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        return '<!DOCTYPE html>'
            .'<html lang="en">'
            .'<head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow">'
            .'<title>'.$escapedTitle.'</title>'
            .'<style'.$nonceAttribute.'>'.str_ireplace('</style', '<\/style', $styles).'</style>'
            .'</head>'
            .'<body>'
            .'<div id="vlog"></div>'
            .'<script type="application/json" id="vlog-config">'.$config.'</script>'
            .'<script type="module"'.$nonceAttribute.'>'.str_ireplace('</script', '<\/script', $script).'</script>'
            .'</body>'
            .'</html>';
    }

    /**
     * Get the headers the page should be sent with. Logs can hold sensitive
     * data, so the page is never cached and can't be framed by other sites.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];
    }

    private function asset(string $name): ?string
    {
        $path = $this->assetDirectory.DIRECTORY_SEPARATOR.$name;
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $contents === false ? null : $contents;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
