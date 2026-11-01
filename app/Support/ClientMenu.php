<?php

namespace App\Support;

use App\Actions\ClientOrder;
use App\Filament\Client\Pages\CustomPageView;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Pages\ProductGroupPage;
use App\Filament\Client\Pages\ProductPage;
use App\Models\CustomPage;
use App\Models\Product;
use App\Models\ProductGroup;
use Closure;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use ReflectionClass;

/**
 * The client area's two side menus, as Settings → Client area → Menus arranges them: the signed-in clients' (CLIENT,
 * built from the client panel's own pages and resources) and the visitors' (PUBLIC, PublicArea::menuEntries()).
 *
 * A saved menu (`client_area.menu` / `public_area.menu`) is {items, seen}: `items` is the menu in order, each row one
 * of TYPES; a heading starts a section that holds the rows after it, rows before the first heading stand alone at the
 * top. `seen` lists the pages there were when it was saved, so a page an update adds later (not seen) still shows, at
 * the end of its own section, while a page the admin took out stays out. Nothing saved = the menu as it comes.
 */
final class ClientMenu
{
    public const CLIENT = 'client';

    public const PUBLIC = 'public';

    /** page (a built-in one, by key), heading, product, category (a product group), custom_page (Support → Pages), link */
    public const TYPES = ['page', 'heading', 'product', 'category', 'custom_page', 'link'];

    /** @var array<string, string> the signed-in menu's headings as they come (ClientPanelProvider): key => English name */
    public const CLIENT_HEADINGS = ['services' => 'Services', 'billing' => 'Billing', 'support' => 'Support'];

    /** @var array<string, array{0: string, 1: string}> icons a menu line can take beyond the product icons: key => [Heroicon, English label] */
    public const ICONS = [
        'link' => ['heroicon-o-link', 'Link'],
        'document' => ['heroicon-o-document-text', 'Document'],
        'download' => ['heroicon-o-arrow-down-tray', 'Download'],
        'info' => ['heroicon-o-information-circle', 'Information'],
        'question' => ['heroicon-o-question-mark-circle', 'Question'],
        'home' => ['heroicon-o-home', 'Home'],
        'user' => ['heroicon-o-user', 'Person'],
        'tag' => ['heroicon-o-tag', 'Tag'],
        'gift' => ['heroicon-o-gift', 'Gift'],
        'phone' => ['heroicon-o-phone', 'Phone'],
    ];

    public static function settingKey(string $menu): string
    {
        return $menu === self::PUBLIC ? 'public_area.menu' : 'client_area.menu';
    }

    /** @return array{items: list<array<string, mixed>>, seen: list<string>}|null what is saved for the menu; null = as it comes */
    public static function saved(string $menu): ?array
    {
        $value = config('gaiacp.'.self::settingKey($menu));

        return is_array($value) && is_array($value['items'] ?? null) ? ['items' => array_values($value['items']), 'seen' => array_values((array) ($value['seen'] ?? []))] : null;
    }

    /** @return array<string, string> heading key => its name now */
    public static function headings(string $menu): array
    {
        return array_map(fn (string $name) => __($name), $menu === self::PUBLIC ? PublicArea::MENU_HEADINGS : self::CLIENT_HEADINGS);
    }

    /**
     * Every built-in page the menu can show, in the order it comes, whether it is on now or not (Settings lists them).
     *
     * @return array<string, array{name: string, icon: ?string, heading: ?string}>
     */
    public static function pages(string $menu): array
    {
        if ($menu === self::PUBLIC) {
            return array_map(fn (array $entry) => ['name' => $entry[0], 'icon' => $entry[1], 'heading' => $entry[2]], PublicArea::menuEntries());
        }

        return self::inClientPanel(fn () => collect(self::clientItems(checkAccess: false))
            ->map(fn (array $entry) => ['name' => (string) $entry['items'][0]->getLabel(), 'icon' => self::iconName($entry['items'][0]->getIcon()), 'heading' => $entry['heading']])
            ->all());
    }

    /**
     * The menu as it comes: its headings and built-in pages in their usual order.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultRows(string $menu): array
    {
        $pages = self::pages($menu);
        $rows = [];

        foreach ($pages as $key => $page) {
            if ($page['heading'] === null) {
                $rows[] = ['type' => 'page', 'key' => $key];
            }
        }

        foreach (array_keys(self::headings($menu)) as $heading) {
            $rows[] = ['type' => 'heading', 'key' => $heading];

            foreach ($pages as $key => $page) {
                if ($page['heading'] === $heading) {
                    $rows[] = ['type' => 'page', 'key' => $key];
                }
            }
        }

        return $rows;
    }

    /**
     * The saved menu with the pages added since it was saved (not in `seen`) at the end of their own section, or the
     * menu as it comes when nothing is saved.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $menu): array
    {
        $saved = self::saved($menu);

        if ($saved === null) {
            return self::defaultRows($menu);
        }

        $rows = $saved['items'];
        $present = collect($rows)->where('type', 'page')->pluck('key')->all();

        foreach (self::pages($menu) as $key => $page) {
            if (in_array($key, $present, true) || in_array($key, $saved['seen'], true)) {
                continue;
            }

            array_splice($rows, self::endOfSection($rows, $page['heading']), 0, [['type' => 'page', 'key' => $key]]);
        }

        return $rows;
    }

    /** The side menu, built from rows(): items before the first heading, then a group per heading that has any. */
    public static function build(string $menu, NavigationBuilder $builder): NavigationBuilder
    {
        $builtIn = $menu === self::PUBLIC
            ? array_map(fn (array $entry) => ($entry[3])(), PublicArea::menuEntries())
            : collect(self::clientItems(checkAccess: true))->map(fn (array $entry) => $entry['items'])->all();
        $headings = self::headings($menu);
        $rows = self::rows($menu);
        $products = Product::query()->whereIn('id', collect($rows)->where('type', 'product')->pluck('product'))->with('group')->get()->keyBy('id');
        $groups = ProductGroup::query()->whereIn('id', collect($rows)->where('type', 'category')->pluck('category'))->get()->keyBy('id');
        $customPages = CustomPage::query()->published()->whereIn('id', collect($rows)->where('type', 'custom_page')->pluck('custom_page'))->get()->keyBy('id');

        $top = [];
        $sections = [];
        $sort = 0;

        foreach ($rows as $row) {
            if (($row['type'] ?? null) === 'heading') {
                $sections[] = ['label' => self::label($row) ?? $headings[$row['key'] ?? ''] ?? '', 'items' => []];

                continue;
            }

            foreach (self::items($menu, $row, $builtIn, $products, $groups, $customPages) as $item) {
                $item->sort(++$sort)->group(null);

                if ($sections === []) {
                    $top[] = $item;
                } else {
                    $sections[array_key_last($sections)]['items'][] = $item;
                }
            }
        }

        return $builder
            ->items($top)
            ->groups(collect($sections)
                ->filter(fn (array $section) => $section['items'] !== [])
                ->map(fn (array $section) => NavigationGroup::make($section['label'])->collapsible(false)->items($section['items']))
                ->values()
                ->all());
    }

    /** @return array<string, string> every icon a menu line can take: key => its name */
    public static function iconOptions(): array
    {
        return collect([...ProductIcons::ICONS, ...self::ICONS])->map(fn (array $icon) => __($icon[1]))->sort()->all();
    }

    public static function icon(?string $key): ?string
    {
        return [...ProductIcons::ICONS, ...self::ICONS][$key ?? ''][0] ?? null;
    }

    /** A row's own name in the language in use (else the default language's, else any), or null for the usual one. */
    public static function label(array $row): ?string
    {
        $labels = array_filter((array) ($row['label'] ?? []), fn ($text) => is_string($text) && trim($text) !== '');

        return $labels[app()->getLocale()] ?? $labels[Locales::default()] ?? (reset($labels) ?: null);
    }

    /**
     * @param  array<string, list<NavigationItem>>  $builtIn
     * @return list<NavigationItem>
     */
    private static function items(string $menu, array $row, array $builtIn, $products, $groups, $customPages): array
    {
        $label = self::label($row);
        $icon = self::icon($row['icon'] ?? null);

        $items = match ($row['type'] ?? null) {
            'page' => array_map(fn (NavigationItem $item) => clone $item, $builtIn[$row['key'] ?? ''] ?? []),
            'product' => self::productItem($menu, $products->get((int) ($row['product'] ?? 0))),
            'category' => self::categoryItem($menu, $groups->get((int) ($row['category'] ?? 0))),
            'custom_page' => self::customPageItem($menu, $customPages->get((int) ($row['custom_page'] ?? 0))),
            'link' => filled($row['url'] ?? null) && $label !== null ? [self::linkItem('link', $label, (string) $row['url'], 'heroicon-o-link')->openUrlInNewTab((bool) ($row['new_tab'] ?? false))] : [],
            default => [],
        };

        foreach ($items as $item) {
            if ($label !== null && count($items) === 1) {
                $item->label($label);
            }

            if ($icon !== null) {
                $item->icon($icon);
            }
        }

        return $items;
    }

    /** @return list<NavigationItem> a product clients can order: its public page, else the order page with it picked */
    private static function productItem(string $menu, ?Product $product): array
    {
        if ($product === null || ! ClientOrder::products()->contains('id', $product->id)) {
            return [];
        }

        $url = ProductPage::urlFor($product) ?? match (true) {
            $menu === self::CLIENT && Order::canAccess() => Order::getUrl(['group' => OrderCatalogue::keyFor($product->id)]).'?product='.$product->id,
            $menu === self::PUBLIC && ClientOrder::publicEnabled() => route('filament.client.cart', ['group' => OrderCatalogue::keyFor($product->id)]).'?product='.$product->id,
            default => null,
        };

        return $url === null ? [] : [self::linkItem('product-'.$product->id, $product->localName(), $url, ProductIcons::ICONS[$product->icon ?? ''][0] ?? 'heroicon-o-shopping-cart')];
    }

    /** @return list<NavigationItem> a product group: its public page, else the order page on it */
    private static function categoryItem(string $menu, ?ProductGroup $group): array
    {
        if ($group === null || ! OrderCatalogue::groups()->has($group->slug)) {
            return [];
        }

        $url = ProductGroupPage::urlFor($group) ?? match (true) {
            $menu === self::CLIENT && Order::canAccess() => Order::getUrl(['group' => $group->slug]),
            $menu === self::PUBLIC && ClientOrder::publicEnabled() => route('filament.client.cart', ['group' => $group->slug]),
            default => null,
        };

        return $url === null ? [] : [self::linkItem('category-'.$group->id, $group->name(), $url, ProductIcons::ICONS[$group->icon ?? ''][0] ?? 'heroicon-o-squares-2x2')];
    }

    /** @return list<NavigationItem> a published page of your own; in the visitors' menu, not one kept for clients */
    private static function customPageItem(string $menu, ?CustomPage $page): array
    {
        if ($page === null || ($menu === self::PUBLIC && $page->clients_only)) {
            return [];
        }

        $request = request();

        return [self::linkItem('custom-page-'.$page->id, $page->title(), CustomPageView::urlFor($page), 'heroicon-o-document-text')
            ->isActiveWhen(fn () => $request->routeIs(CustomPageView::getRouteName()) && $request->route('slug') === $page->slug)];
    }

    private static function linkItem(string $key, string $label, string $url, string $icon): NavigationItem
    {
        return NavigationItem::make($label)
            ->key($key)
            ->icon($icon)
            ->url($url)
            ->isActiveWhen(fn () => in_array($url, [request()->fullUrl(), request()->url()], true));
    }

    /**
     * The signed-in menu's built-in entries, from the client panel's pages and resources, in Filament's order: key (the
     * class, or its item's own key when it has several, like Hosting and Other services) => [heading, items].
     *
     * @return array<string, array{heading: ?string, items: list<NavigationItem>}>
     */
    private static function clientItems(bool $checkAccess): array
    {
        $panel = Filament::getPanel('client');
        $headings = self::CLIENT_HEADINGS;
        $entries = [];

        foreach ([...$panel->getPages(), ...$panel->getResources()] as $class) {
            if (! self::listed($class) || ($checkAccess && ! ($class::shouldRegisterNavigation() && $class::canAccess()))) {
                continue;
            }

            $items = $class::getNavigationItems();

            foreach ($items as $item) {
                $group = $item->getGroup();
                $group = $group instanceof \UnitEnum ? $group->name : (string) $group;
                $heading = collect($headings)->search(fn (string $name) => $group === $name || $group === __($name)) ?: null;
                $key = count($items) > 1 ? class_basename($item->getKey()) : class_basename($class);
                $entries[$key] = ['heading' => $heading, 'items' => [$item], 'sort' => $item->getSort()];
            }
        }

        uasort($entries, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $entry) => ['heading' => $entry['heading'], 'items' => $entry['items']], $entries);
    }

    /**
     * Whether a page or resource can be in the menu at all: not one that never is (`$shouldRegisterNavigation = false`
     * without a shouldRegisterNavigation() of its own), nor one of a cluster.
     *
     * @param  class-string<Page|resource>  $class
     */
    private static function listed(string $class): bool
    {
        if (filled($class::getCluster())) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->getMethod('shouldRegisterNavigation')->getDeclaringClass()->getName() === $class) {
            return true;
        }

        return (bool) $reflection->getStaticPropertyValue('shouldRegisterNavigation', true);
    }

    /** Runs it as the client panel (its pages' URLs and labels), whichever panel is current. */
    private static function inClientPanel(Closure $callback): mixed
    {
        $current = Filament::getCurrentPanel();

        if ($current?->getId() === 'client') {
            return $callback();
        }

        Filament::setCurrentPanel('client');

        try {
            return $callback();
        } finally {
            Filament::setCurrentPanel($current);
        }
    }

    private static function iconName(mixed $icon): ?string
    {
        return $icon instanceof \BackedEnum ? 'heroicon-'.$icon->value : (is_string($icon) ? $icon : null);
    }

    /** Where a page that is new since the menu was saved goes: the end of its section, else before the first heading. */
    private static function endOfSection(array $rows, ?string $heading): int
    {
        $start = $heading === null ? null : collect($rows)->search(fn (array $row) => ($row['type'] ?? null) === 'heading' && ($row['key'] ?? null) === $heading);

        if ($heading === null || $start === false) {
            $first = collect($rows)->search(fn (array $row) => ($row['type'] ?? null) === 'heading');

            return $heading === null && $first !== false ? $first : count($rows);
        }

        $next = collect($rows)->slice($start + 1)->search(fn (array $row) => ($row['type'] ?? null) === 'heading');

        return $next === false ? count($rows) : $next;
    }
}
