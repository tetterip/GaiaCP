<?php

namespace Plugins\PiraeusBank;

use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The one call to Piraeus Bank's servers: IssueNewTicket, a SOAP request written by hand (no PHP SOAP extension
 * needed). In the module log (piraeusbank) with the password digest and the ticket masked.
 */
class PiraeusBankApi
{
    /**
     * @param  array<string, string>  $request  the ticket request fields
     * @return string the transaction ticket
     *
     * @throws PiraeusBankException
     */
    public function issueTicket(array $request): string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['SOAPAction' => '"'.Paycenter::NAMESPACE.'/IssueNewTicket"'])
                ->withBody(Paycenter::ticketEnvelope($request), 'text/xml; charset=utf-8')
                ->withMiddleware(ModuleLogger::middleware('piraeusbank', 'issue_ticket', [$request['Password'] ?? ''], ['TranTicket']))
                ->post(Paycenter::TICKET_URL);
        } catch (ConnectionException) {
            throw new PiraeusBankException(__('Piraeus Bank could not be reached. Please try again.'));
        }

        $result = Paycenter::ticketResult((string) $response->body());

        if ($result === null) {
            throw new PiraeusBankException(__('Piraeus Bank refused the request: :error', ['error' => 'HTTP '.$response->status()]));
        }

        if ($result['ResultCode'] !== '0' || $result['TranTicket'] === '') {
            throw new PiraeusBankException(__('Piraeus Bank refused the request: :error', ['error' => trim($result['ResultCode'].' '.$result['ResultDescription'])]), (int) $result['ResultCode']);
        }

        return $result['TranTicket'];
    }
}
