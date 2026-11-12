<?php

namespace Plugins\PiraeusBank;

use Illuminate\Support\Str;
use Symfony\Component\Intl\Countries;

/**
 * Piraeus Bank's epay paycenter redirect protocol: the ticket request (IssueNewTicket), the form that takes the client
 * to the bank, and the HashKey that signs the bank's answer with the attempt's transaction ticket.
 *
 * Ported from the WooCommerce plugin "Piraeus Bank WooCommerce Payment Gateway" by Papaki (Enartia S.A.), itself based
 * on emspace.gr's, GPL-3.0-or-later, as maintained in tetterip/woo-tet-payment-gateway-for-piraeus-bank. Pure.
 */
class Paycenter
{
    public const TICKET_URL = 'https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx';

    public const PAY_URL = 'https://paycenter.piraeusbank.gr/redirection/pay.aspx';

    public const NAMESPACE = 'http://piraeusbank.gr/paycenter/redirection';

    /** ResponseCode values of an approved payment. */
    public const APPROVED = ['0', '8', '10', '16'];

    /** ResponseCode of a payment the bank already approved for this reference ("previously processed"). */
    public const ALREADY_APPROVED = '11';

    /** Currencies the bank takes: their ISO 4217 numeric codes (the original plugin's list, less the yen, in whole units). */
    public const CURRENCIES = ['EUR' => '978', 'USD' => '840', 'GBP' => '826', 'CHF' => '756', 'BGN' => '975', 'RON' => '946', 'CZK' => '203', 'PLN' => '985',
        'HUF' => '348', 'SEK' => '752', 'DKK' => '208', 'NOK' => '578', 'CAD' => '124', 'AUD' => '036', 'TRY' => '949', 'RSD' => '941', 'ALL' => '008', 'MKD' => '807'];

    /** A MerchantReference: the notice's id and a random part, so an answer can't be made up for a guessed one. */
    public static function reference(int $invoiceId): string
    {
        return mb_substr($invoiceId.'-'.strtoupper(Str::random(16)), 0, 50);
    }

    /** The bank wants the MD5 of the password, as the original plugin sent it. */
    public static function passwordDigest(string $password): string
    {
        return md5($password);
    }

    /** "60.00" for 6000 hundredths. */
    public static function amount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The bank's page language for a GaiaCP locale. */
    public static function language(string $locale): string
    {
        return match (substr($locale, 0, 2)) {
            'el' => 'el-GR',
            'de' => 'de-DE',
            'ru' => 'ru-RU',
            default => 'en-US',
        };
    }

    /** ISO 3166 numeric country code ("300" for GR), as 3-D Secure wants it; '' when unknown. */
    public static function countryCode(string $country): string
    {
        $country = strtoupper(trim($country));

        return Countries::exists($country) ? Countries::getNumericCode($country) : '';
    }

    /**
     * A Greek number as 3-D Secure wants it ("30-69…"), with whether it is a mobile or a fixed line; null for any other.
     *
     * @return array{type: 'mobile'|'home', number: string}|null
     */
    public static function greekPhone(string $phone, string $country): ?array
    {
        $trimmed = trim($phone);
        $digits = (string) preg_replace('/\D/', '', $trimmed);
        $prefixed = false;

        if (str_starts_with($trimmed, '+') && str_starts_with($digits, '30')) {
            [$digits, $prefixed] = [substr($digits, 2), true];
        } elseif (str_starts_with($digits, '0030')) {
            [$digits, $prefixed] = [substr($digits, 4), true];
        } elseif (str_starts_with($digits, '30') && strlen($digits) === 12) {
            [$digits, $prefixed] = [substr($digits, 2), true];
        }

        if ((! $prefixed && strtoupper(trim($country)) !== 'GR') || strlen($digits) !== 10) {
            return null;
        }

        return match (true) {
            str_starts_with($digits, '69') => ['type' => 'mobile', 'number' => '30-'.$digits],
            $digits[0] === '2' => ['type' => 'home', 'number' => '30-'.$digits],
            default => null,
        };
    }

    /**
     * The HashKey the bank signs its answer with: HMAC-SHA256 keyed with the transaction ticket, or the older plain
     * SHA-256. Both need the ticket, which only GaiaCP and the bank know.
     *
     * @param  array<string, string>  $answer  the bank's answer fields
     */
    public static function verify(array $answer, string $ticket, string $posId, string $acquirerId): bool
    {
        $received = strtoupper(trim($answer['HashKey'] ?? ''));

        if ($received === '' || $ticket === '') {
            return false;
        }

        $fields = [$posId, $acquirerId, $answer['MerchantReference'] ?? ''];

        foreach (['ApprovalCode', 'Parameters', 'ResponseCode', 'SupportReferenceID', 'AuthStatus', 'PackageNo', 'StatusFlag'] as $name) {
            $fields[] = $answer[$name] ?? '';
        }

        $hmac = strtoupper(hash_hmac('sha256', $ticket.';'.implode(';', $fields), $ticket));
        $plain = strtoupper(hash('sha256', $ticket.implode('', $fields)));

        return hash_equals($hmac, $received) || hash_equals($plain, $received);
    }

    /** Whether the answer is an IRIS transfer the bank has not confirmed yet (ResponseCode 09). */
    public static function irisPending(array $answer): bool
    {
        $iris = trim($answer['CardType'] ?? '') === '15' || strtoupper(trim($answer['PaymentMethod'] ?? '')) === 'IRIS';

        return $iris && ltrim(trim($answer['ResponseCode'] ?? ''), '0') === '9';
    }

    /**
     * The SOAP envelope of IssueNewTicket.
     *
     * @param  array<string, string>  $request  the ticket request fields, in the order sent
     */
    public static function ticketEnvelope(array $request): string
    {
        $fields = collect($request)->map(fn (string $value, string $name) => '<'.$name.'>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$name.'>')->join('');

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<IssueNewTicket xmlns="'.self::NAMESPACE.'"><Request>'.$fields.'</Request></IssueNewTicket>'
            .'</soap:Body></soap:Envelope>';
    }

    /**
     * IssueNewTicketResult from the bank's SOAP answer: ResultCode, ResultDescription, TranTicket; null when it is not one.
     *
     * @return array{ResultCode: string, ResultDescription: string, TranTicket: string}|null
     */
    public static function ticketResult(string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($document === false) {
            return null;
        }

        $result = $document->xpath('//*[local-name()="IssueNewTicketResult"]')[0] ?? null;

        if ($result === null) {
            return null;
        }

        $value = fn (string $name) => trim((string) ($result->xpath('*[local-name()="'.$name.'"]')[0] ?? ''));

        return ['ResultCode' => $value('ResultCode'), 'ResultDescription' => $value('ResultDescription'), 'TranTicket' => $value('TranTicket')];
    }
}
