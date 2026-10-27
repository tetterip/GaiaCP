<?php

namespace App\Support;

/**
 * The icons a product group or a product can show on the order page and the public home page (Settings → Product groups,
 * a product's Order page section): a short key stored on the record, drawn as a Heroicon.
 */
final class ProductIcons
{
    /** key => [Heroicon, English label] */
    public const ICONS = [
        'server' => ['heroicon-o-server', 'Server'],
        'servers' => ['heroicon-o-server-stack', 'Servers'],
        'cloud' => ['heroicon-o-cloud', 'Cloud'],
        'cpu' => ['heroicon-o-cpu-chip', 'Processor'],
        'globe' => ['heroicon-o-globe-alt', 'Globe'],
        'lock' => ['heroicon-o-lock-closed', 'Lock'],
        'shield' => ['heroicon-o-shield-check', 'Shield'],
        'envelope' => ['heroicon-o-envelope', 'Envelope'],
        'storage' => ['heroicon-o-circle-stack', 'Storage'],
        'archive' => ['heroicon-o-archive-box', 'Backup'],
        'cart' => ['heroicon-o-shopping-cart', 'Shop'],
        'code' => ['heroicon-o-code-bracket', 'Programming'],
        'wrench' => ['heroicon-o-wrench-screwdriver', 'Tools'],
        'rocket' => ['heroicon-o-rocket-launch', 'Rocket'],
        'bolt' => ['heroicon-o-bolt', 'Bolt'],
        'star' => ['heroicon-o-star', 'Star'],
        'chat' => ['heroicon-o-chat-bubble-left-right', 'Support'],
        'squares' => ['heroicon-o-squares-2x2', 'Squares'],
    ];

    /** @return array<string, string> for a Select with allowHtml(): each icon beside its name, as HTML */
    public static function options(): array
    {
        return collect(self::ICONS)->mapWithKeys(fn (array $icon, string $key) => [
            $key => '<span style="display:inline-flex;align-items:center;gap:.5rem">'.self::svg($key).e(__($icon[1])).'</span>',
        ])->all();
    }

    /** The icon's SVG (1.25rem), or an empty string for none or an unknown key. */
    public static function svg(?string $key, string $class = 'gcp-product-icon'): string
    {
        $icon = self::ICONS[$key ?? ''][0] ?? null;

        return $icon === null ? '' : svg($icon, $class, ['style' => 'width:1.25rem;height:1.25rem;flex:none'])->toHtml();
    }
}
