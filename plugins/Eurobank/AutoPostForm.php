<?php

namespace Plugins\Eurobank;

use Illuminate\Http\Response;

/**
 * A small page that posts a form to the bank as soon as it loads (a button does it without JavaScript). The bank's
 * redirect flow needs the client's browser to make that POST: a redirect can't carry the signed fields.
 */
class AutoPostForm
{
    /** @param  array{url: string, fields: array<string, string>}  $form */
    public static function response(array $form, string $message, string $button): Response
    {
        $inputs = collect($form['fields'])
            ->map(fn (string $value, string $name) => '<input type="hidden" name="'.e($name).'" value="'.e($value).'">')
            ->join("\n");

        $html = '<!DOCTYPE html><html lang="'.e(str_replace('_', '-', app()->getLocale())).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.e($message).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;display:flex;min-height:90vh;align-items:center;justify-content:center;text-align:center;color:#333;background:#fff}'
            .'button{font:inherit;padding:.6em 1.4em;margin-top:1em;border-radius:.4em;border:1px solid #888;background:#f4f4f4;cursor:pointer}'
            .'@media (prefers-color-scheme:dark){body{color:#ddd;background:#18181b}button{background:#27272a;color:#ddd}}</style></head><body>'
            .'<form id="bank" method="post" action="'.e($form['url']).'" accept-charset="UTF-8">'.$inputs
            .'<p>'.e($message).'</p><button type="submit">'.e($button).'</button></form>'
            .'<script>document.getElementById("bank").submit();</script></body></html>';

        return response($html)->header('Cache-Control', 'no-store');
    }
}
