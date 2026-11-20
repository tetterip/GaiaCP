<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A client-area menu label with a quiet grey count after it ("Hosting 3"): how many the page lists. The count is read
 * only when the menu is drawn, and none shows at 0. It is not the item's badge, which stays for what needs the client
 * (suspended services, domains ending without auto renew) in red or amber at the end of the line. As a string it is the
 * plain name, so the menu settings and the collapsed menu's tooltip read only that.
 */
class NavigationCount implements Htmlable
{
    /** @param  Closure(): int  $count */
    public function __construct(public readonly string $text, private readonly Closure $count) {}

    /** The same count after another name (a menu line renamed in Settings → Client area → Menus). */
    public function withText(string $text): self
    {
        return new self($text, $this->count);
    }

    public function count(): int
    {
        return ($this->count)();
    }

    public function toHtml(): string
    {
        $count = $this->count();

        return e($this->text).($count > 0 ? ' <span class="gcp-nav-count">'.$count.'</span>' : '');
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
