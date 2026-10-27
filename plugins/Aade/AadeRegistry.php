<?php

namespace Plugins\Aade;

use App\Models\ActivityLog;
use App\Plugins\PluginManager;
use App\Support\ModuleLogger;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ΑΑΔΕ "Αναζήτηση Βασικών Στοιχείων Μητρώου Επιχειρήσεων" (RgWsPublic2): the registry details of an ΑΦΜ.
 * SOAP 1.2 with a WS-Security UsernameToken holding the special access codes (ειδικοί κωδικοί) from
 * Settings → ΑΑΔΕ. Built by hand rather than with ext-soap, the same request as a working
 * WooCommerce invoicing plugin (Basic auth and SOAP 1.1 get HTTP 415 from gsis.gr).
 */
class AadeRegistry
{
    public const ENDPOINT = 'https://www1.gsis.gr/wsaade/RgWsPublic2/RgWsPublic2';

    /** Switched on under Settings → ΑΑΔΕ; the client form shows the lookup button only then. */
    public static function enabled(): bool
    {
        return app(PluginManager::class)->enabled('aade') && (bool) config('gaiacp.aade.enabled');
    }

    public static function configured(): bool
    {
        return filled(config('gaiacp.aade.username')) && filled(config('gaiacp.aade.password'));
    }

    /**
     * @return array{afm: string, name: string, commercial_title: ?string, tax_office: ?string, profession: ?string,
     *     address: ?string, postcode: ?string, city: ?string, legal_form: ?string, active: bool, status: ?string}
     *
     * @throws AadeException
     */
    public static function lookup(string $afm): array
    {
        $afm = Afm::clean($afm);

        if (! Afm::isValid($afm)) {
            throw new AadeException('This is not a valid ΑΦΜ: it needs 9 digits and the last one does not match. Check it for typos.');
        }

        if (! self::enabled()) {
            throw new AadeException('ΑΑΔΕ lookups are switched off under Settings → ΑΑΔΕ.');
        }

        if (! self::configured()) {
            throw new AadeException('ΑΑΔΕ lookups are not set up: enter the special access codes under Settings → ΑΑΔΕ.');
        }

        try {
            $result = self::parse(self::call($afm));
        } catch (AadeException $e) {
            ActivityLog::record('aade.failed', "ΑΑΔΕ lookup of ΑΦΜ {$afm} failed: {$e->getMessage()}");

            throw $e;
        }

        ActivityLog::record('aade.lookup', "ΑΑΔΕ lookup of ΑΦΜ {$afm}: {$result['name']}");

        return $result;
    }

    private static function call(string $afm): string
    {
        try {
            $response = Http::withBody(self::envelope($afm), 'application/soap+xml; charset=utf-8')
                ->withMiddleware(ModuleLogger::middleware('aade', 'rgWsPublic2AfmMethod', [config('gaiacp.aade.password')]))
                ->connectTimeout(10)
                ->timeout(20)
                ->post(self::ENDPOINT);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new AadeException("Cannot reach ΑΑΔΕ: {$reason}", previous: $e);
        }

        // A SOAP fault comes back as HTTP 500 with a readable reason in the body.
        if ($response->failed() && ! str_contains($response->body(), 'Fault')) {
            throw new AadeException("ΑΑΔΕ answered with HTTP {$response->status()}. The service may be down; try again later.");
        }

        return $response->body();
    }

    private static function envelope(string $afm): string
    {
        $xml = fn (?string $value) => htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');
        $username = $xml(config('gaiacp.aade.username'));
        $password = $xml(config('gaiacp.aade.password'));
        $calledBy = $xml(Afm::clean(config('gaiacp.aade.afm_called_by')));

        return <<<XML
<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" xmlns:ns1="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:ns2="http://rgwspublic2/RgWsPublic2Service" xmlns:ns3="http://rgwspublic2/RgWsPublic2">
   <env:Header>
      <ns1:Security>
         <ns1:UsernameToken>
            <ns1:Username>{$username}</ns1:Username>
            <ns1:Password>{$password}</ns1:Password>
         </ns1:UsernameToken>
      </ns1:Security>
   </env:Header>
   <env:Body>
      <ns2:rgWsPublic2AfmMethod>
         <ns2:INPUT_REC>
            <ns3:afm_called_by>{$calledBy}</ns3:afm_called_by>
            <ns3:afm_called_for>{$afm}</ns3:afm_called_for>
         </ns2:INPUT_REC>
      </ns2:rgWsPublic2AfmMethod>
   </env:Body>
</env:Envelope>
XML;
    }

    /** @return array<string, mixed> see lookup() */
    private static function parse(string $body): array
    {
        $dom = new DOMDocument;
        if ($body === '' || ! @$dom->loadXML($body, LIBXML_NONET)) {
            throw new AadeException('ΑΑΔΕ returned a reply that could not be read.');
        }

        $xpath = new DOMXPath($dom);
        // Prefixes vary between replies, so match on local names only.
        $text = function (string $path) use ($xpath): ?string {
            $steps = implode('/', array_map(fn ($step) => "*[local-name()='{$step}']", explode('/', $path)));
            $value = trim((string) $xpath->evaluate("string(//{$steps})"));

            return $value === '' ? null : preg_replace('/\s+/u', ' ', $value);
        };

        if ($fault = $text('Fault/Reason/Text') ?? $text('Fault/faultstring')) {
            throw new AadeException("ΑΑΔΕ refused the request: {$fault}");
        }

        $code = $text('error_rec/error_code');
        if ($code !== null && $code !== 'RG_WS_SUCCESS') {
            throw new AadeException(self::errorMessage($code, $text('error_rec/error_descr')));
        }

        $name = $text('basic_rec/onomasia');
        if ($name === null) {
            throw new AadeException('ΑΑΔΕ has no business registered under this ΑΦΜ.');
        }

        // The main activity (kind 1, ΚΥΡΙΑ), else the first one listed.
        $profession = null;
        foreach ($xpath->query("//*[local-name()='firm_act_tab']/*") as $activity) {
            $kind = trim((string) $xpath->evaluate("string(*[local-name()='firm_act_kind'])", $activity));
            $descr = trim((string) $xpath->evaluate("string(*[local-name()='firm_act_descr'])", $activity));
            if ($descr !== '' && ($kind === '1' || $profession === null)) {
                $profession = $descr;
                if ($kind === '1') {
                    break;
                }
            }
        }

        $street = trim(implode(' ', array_filter([$text('basic_rec/postal_address'), $text('basic_rec/postal_address_no')])));

        return [
            'afm' => (string) $text('basic_rec/afm'),
            'name' => $name,
            'commercial_title' => $text('basic_rec/commer_title'),
            'tax_office' => $text('basic_rec/doy_descr'),
            'profession' => $profession,
            'address' => $street === '' ? null : $street,
            'postcode' => $text('basic_rec/postal_zip_code'),
            'city' => $text('basic_rec/postal_area_description'),
            'legal_form' => $text('basic_rec/legal_status_descr'),
            // deactivation_flag: 1 = active ΑΦΜ, 2 = deactivated.
            'active' => $text('basic_rec/deactivation_flag') !== '2',
            'status' => $text('basic_rec/deactivation_flag_descr'),
        ];
    }

    private static function errorMessage(string $code, ?string $description): string
    {
        $hint = match (true) {
            str_contains($code, 'TOKEN') || str_contains($code, 'AUTH') => ' Check the username and password under Settings → ΑΑΔΕ (they are the special access codes, not your TAXISnet login).',
            default => '',
        };

        return 'ΑΑΔΕ: '.($description ?? $code).$hint;
    }
}
