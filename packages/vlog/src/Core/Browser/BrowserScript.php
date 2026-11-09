<?php

namespace Rss\Vlog\Core\Browser;

/**
 * The small script that reports errors from visitors' browsers.
 *
 * It listens for uncaught errors, unhandled promise rejections and files
 * that fail to load, and sends them in batches with navigator.sendBeacon().
 * Each page reports at most 20 distinct errors, so a loop that throws can't
 * flood the log.
 */
final class BrowserScript
{
    /**
     * The attribute that marks the script, so it's never added twice.
     */
    public const MARKER = 'data-vlog-browser';

    private const SCRIPT = <<<'JS'
(function(){if(window.__vlogBrowser)return;window.__vlogBrowser=1;var e=__ENDPOINT__,q=[],s={},n=0,t=0;function f(){t=0;if(!q.length)return;var b=JSON.stringify({errors:q.splice(0,10)});try{if(!(navigator.sendBeacon&&navigator.sendBeacon(e,new Blob([b],{type:"text/plain"}))))fetch(e,{method:"POST",body:b,keepalive:true,credentials:"omit",headers:{"Content-Type":"text/plain"}})}catch(x){}}function r(o){if(n>=20||!o.message||o.message==="Script error.")return;var k=o.type+o.message+(o.line||"");if(s[k])return;s[k]=1;n++;o.url=location.href;q.push(o);if(!t)t=setTimeout(f,1000)}addEventListener("error",function(v){var g=v.target;if(g&&g!==window&&g.tagName){var u=g.src||g.href;if(u)r({type:"resource",message:"Failed to load "+g.tagName.toLowerCase()+": "+u,source:String(u)});return}var x=v.error;r({type:"error",message:String(v.message||(x&&x.message)||""),source:v.filename||"",line:v.lineno||0,column:v.colno||0,stack:x&&x.stack?String(x.stack):""})},true);addEventListener("unhandledrejection",function(v){var x=v.reason;r({type:"unhandledrejection",message:"Unhandled promise rejection: "+(x&&x.message?x.message:String(x)),stack:x&&x.stack?String(x.stack):""})});addEventListener("pagehide",f)})();
JS;

    /**
     * Render the script tag.
     *
     * @param  string  $endpoint  The URL reports are sent to.
     * @param  string|null  $nonce  A Content Security Policy nonce for the inline script.
     */
    public static function tag(string $endpoint, ?string $nonce = null): string
    {
        $encodedEndpoint = (string) json_encode($endpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $nonceAttribute = $nonce === null ? '' : ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';

        return '<script '.self::MARKER.$nonceAttribute.'>'.str_replace('__ENDPOINT__', $encodedEndpoint, self::SCRIPT).'</script>';
    }

    /**
     * Add the script tag to an HTML page, before </head> or else before
     * </body>. Pages that already have it, or have neither tag, are returned
     * unchanged.
     */
    public static function inject(string $html, string $tag): string
    {
        if (strpos($html, self::MARKER) !== false) {
            return $html;
        }

        foreach (['</head>', '</body>'] as $closingTag) {
            $position = stripos($html, $closingTag);

            if ($position !== false) {
                return substr($html, 0, $position).$tag.substr($html, $position);
            }
        }

        return $html;
    }
}
