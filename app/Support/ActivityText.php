<?php

namespace App\Support;

use App\Enums\CancellationType;
use App\Models\ActivityLog;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * An Activity log description in the admin's language. Descriptions are stored in English (the log stays auditable
 * whoever wrote it), so a known one is matched against the English text it was written from (TEMPLATES, the same
 * words the code records) and given back through __() with the pieces it held. Dates (2026-12-05) come out in the
 * Region's date format; a description no template knows is shown as stored, with only its dates changed.
 */
final class ActivityText
{
    /**
     * event => the English texts its description is written from, with :placeholders for the pieces. They must stay
     * word for word what the code records (ActivityTextTest builds some through the real actions). One with a
     * singular and a plural ("…|…") is shown with trans_choice() by its :count.
     */
    public const TEMPLATES = [
        'payment.recorded' => [
            'Payment of :amount recorded; next due date :from → :to',
            'Payment of :amount recorded for the change of product; next due date stays :date',
        ],
        'payment.refunded' => [
            'Payment of :amount of :date refunded to credit',
            'Payment of :amount of :date refunded outside GaiaCP',
            'Payment of :amount of :date refunded at :method (:reference)',
        ],
        'payment.reversed' => [
            'Payment of :amount reversed; next due date back to :date',
            'Payment of :amount of :date refunded, order #:order cancelled; returned to credit',
            'Payment of :amount of :date refunded, order #:order cancelled; refunded outside GaiaCP',
        ],
        'order.placed' => [
            'Order #:order placed with :count item and coupon :coupon|Order #:order placed with :count items and coupon :coupon',
            'Order #:order placed with :count item|Order #:order placed with :count items',
        ],
        'order.client_placed' => ['Order #:order placed in the client area'],
        'order.completed' => ['Order #:order completed'],
        'client.created' => [
            'Client :client created from lead :lead',
            'Client :client created through the API',
        ],
        'client.signed_up' => [
            ':client (:email) signed up in the client area with :provider',
            ':client (:email) signed up in the client area',
        ],
        'service.cancellation_requested' => [
            'Cancellation requested (:type), with its domain: :reason',
            'Cancellation requested (:type), with its domain',
            'Cancellation requested (:type): :reason',
            'Cancellation requested (:type)',
        ],
        'service.cancellation_approved' => [
            'Cancellation request approved: terminated',
            'Cancellation request approved: ends on :date',
        ],
        'service.terminated' => [
            'Terminated; account :username removed',
            'Terminated',
        ],
        'service.auto_terminated' => [
            'Terminated at the end of its cycle: :reason',
            'Terminated at the end of its cycle',
        ],
        'ticket.client_replied' => [
            'Client replied to ticket :ticket by email',
            'Client replied to ticket :ticket',
        ],
        'ticket.replied' => ['Ticket :ticket answered'],
        'ticket.closed' => [
            'Ticket :ticket closed: no answer from the client',
            'Ticket :ticket closed',
        ],
        'reminder.sent' => ['Reminder :kind sent to :email for due date :date'],
        'invoice.created' => [
            'Payment notice :number for :amount: the change to :product until :date',
            'Payment notice :number for :amount: the add-on :service until :date',
            'Payment notice :number for :amount due :date',
        ],
        'invoice.cancelled' => [
            'Payment notice :number cancelled: :reason',
            'Payment notice :number cancelled',
        ],
        'invoice.paid' => ['Payment notice :number paid'],
        'invoice.reminder_sent' => ['Payment notice :number: reminder :kind sent to :email'],
        'service.suspended' => ['Suspended: :reason'],
        'service.unsuspended' => ['Unsuspended'],
        'service.activated' => [
            'Add-on :service activated',
            ':service activated',
        ],
        'service.completed' => [':service paid once: completed'],
        'account.created' => ['Account :username created on :server'],
        'domain.renewed' => [':domain renewed for :count year at :registrar|:domain renewed for :count years at :registrar'],
        'domain.synced' => [':domain expires on :date'],
        'email.sent' => [':template sent to :email'],
    ];

    /** The description of $log in the current language */
    public static function of(ActivityLog $log): string
    {
        return self::translate((string) $log->event, (string) $log->description);
    }

    public static function translate(string $event, string $description): string
    {
        // Written by older versions: "with 1 item(s)", "for 2 year(s)"
        $written = str_replace(['item(s)', 'year(s)'], ['items', 'years'], $description);

        foreach (self::TEMPLATES[$event] ?? [] as $template) {
            $forms = explode('|', $template);

            foreach ($forms as $form) {
                $pieces = self::match($form, $written);

                if ($pieces === null) {
                    continue;
                }

                foreach ($pieces as $name => $value) {
                    $pieces[$name] = self::piece($value, $name);
                }

                return count($forms) > 1 ? trans_choice($template, (int) $pieces['count'], $pieces) : __($template, $pieces);
            }
        }

        return self::dates($description);
    }

    /** @return list<string> every template, for the language files (TranslationKeys) */
    public static function keys(): array
    {
        return array_merge(...array_values(self::TEMPLATES));
    }

    /**
     * The pieces of $description held where $template has its :placeholders, or null when it is not written from it.
     *
     * @return array<string, string>|null
     */
    private static function match(string $template, string $description): ?array
    {
        $pattern = preg_replace_callback(
            '/:([a-z_]+)|[^:]+|:/',
            fn (array $m) => isset($m[1]) ? "(?<{$m[1]}>.+?)" : preg_quote($m[0], '/'),
            $template,
        );

        if (preg_match('/^'.$pattern.'$/su', $description, $found) !== 1) {
            return null;
        }

        return array_filter($found, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /** A piece as it reads in the current language: dates in the Region's format, known words translated */
    private static function piece(string $value, string $name): string
    {
        return match ($name) {
            'type' => CancellationType::tryFrom($value)?->getLabel() ?? $value,
            'reason' => $value === Service::SUSPENDED_OVERDUE ? __('Overdue payment') : self::dates($value),
            'template' => __($value),
            default => self::dates($value),
        };
    }

    /** Dates written as 2026-12-05 in the Region's date format */
    public static function dates(string $text): string
    {
        $format = Region::dateFormat();

        return (string) preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})\b/', function (array $m) use ($format) {
            try {
                return CarbonImmutable::createStrict((int) $m[1], (int) $m[2], (int) $m[3])->format($format);
            } catch (Throwable) {
                return $m[0];
            }
        }, $text);
    }
}
