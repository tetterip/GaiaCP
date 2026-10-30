<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * A tiny in-memory Plesk XML API: customers, subscriptions (webspaces) and
 * service plans. $pleskCalls records ["operator.operation", request XML].
 */
trait FakesPlesk
{
    /** @var array<int, array{login: string, external_id: ?string}> */
    protected array $pleskCustomers = [];

    /** @var array<string, array{owner: int, status: int, plan: string, ftp_login: string, ip: string, ftp_password?: string, real_size?: int, traffic?: int, disk_space?: int, max_traffic?: int, box?: int, max_box?: int, db?: int, dbases?: int}> */
    protected array $pleskWebspaces = [];

    /** @var array<string, string> plan name => guid */
    protected array $pleskPlans = ['Starter' => 'guid-starter', 'Business' => 'guid-business'];

    /** @var list<array{site_id: int, type: string, host: string, value: string}> DNS records (host as the full name with a dot at the end, like Plesk) */
    protected array $pleskDnsRecords = [];

    /** @var array<string, array{webspace: string, pvt: string, cert: string, ca: string}> name => certificate installed in a subscription's pool */
    protected array $pleskCertificates = [];

    /** @var list<array{0: string, 1: SimpleXMLElement}> */
    protected array $pleskCalls = [];

    /** @var array<string, array{0: int, 1: string}> "operator.operation" => [errcode, errtext] */
    protected array $pleskFailures = [];

    protected bool $pleskRejectAuth = false;

    protected function fakePlesk(): void
    {
        Http::fake(function (Request $request) {
            // Not a Plesk call (e.g. an SSL provider's): leave it to another fake.
            if (! str_contains($request->url(), '/enterprise/control/agent.php')) {
                return null;
            }

            if ($this->pleskRejectAuth) {
                return $this->pleskXml('<system><status>error</status><errcode>1001</errcode><errtext>Authentication failed</errtext></system>');
            }

            $xml = new SimpleXMLElement($request->body());
            $operator = $xml->children()[0];
            $operation = $operator->children()[0];
            $key = $operator->getName().'.'.$operation->getName();
            $this->pleskCalls[] = [$key, $operation];

            if (isset($this->pleskFailures[$key])) {
                [$code, $text] = $this->pleskFailures[$key];

                return $this->pleskResult($operator->getName(), $operation->getName(), ["<status>error</status><errcode>{$code}</errcode><errtext>{$text}</errtext>"]);
            }

            return $this->pleskResult($operator->getName(), $operation->getName(), $this->pleskHandle($key, $operation));
        });
    }

    /** @return list<string> inner XML of each <result> */
    private function pleskHandle(string $key, SimpleXMLElement $op): array
    {
        $ok = '<status>ok</status>';
        $notFound = ['<status>error</status><errcode>1013</errcode><errtext>Object not found.</errtext>'];

        switch ($key) {
            case 'server.get':
                return [$ok.'<stat><version><plesk_version>18.0.70</plesk_version></version></stat>'];

            case 'ip.get':
                return [$ok.'<addresses><ip_info><ip_address>fe80::1</ip_address><type>shared</type></ip_info><ip_info><ip_address>203.0.113.5</ip_address><type>shared</type></ip_info></addresses>'];

            case 'service-plan.get':
                $name = isset($op->filter->name) ? (string) $op->filter->name : null;
                $plans = $name === null ? $this->pleskPlans : array_intersect_key($this->pleskPlans, [$name => true]);

                return $plans
                    ? array_map(fn ($n, $g) => "{$ok}<id>1</id><name>{$n}</name><guid>{$g}</guid>", array_keys($plans), $plans)
                    : $notFound;

            case 'customer.get':
                $ext = (string) $op->filter->{'external-id'};
                $byId = isset($op->filter->id) ? (int) $op->filter->id : null;
                foreach ($this->pleskCustomers as $id => $c) {
                    if ($byId !== null ? $id === $byId : $c['external_id'] === $ext) {
                        return ["{$ok}<id>{$id}</id><data><gen_info><login>{$c['login']}</login></gen_info></data>"];
                    }
                }

                return $notFound;

            case 'customer.add':
                $login = (string) $op->gen_info->login;
                foreach ($this->pleskCustomers as $c) {
                    if ($c['login'] === $login) {
                        return ['<status>error</status><errcode>1007</errcode><errtext>User account already exists.</errtext>'];
                    }
                }
                $id = count($this->pleskCustomers) + 10;
                $this->pleskCustomers[$id] = ['login' => $login, 'external_id' => (string) $op->gen_info->{'external-id'} ?: null];

                return ["{$ok}<id>{$id}</id><guid>c-{$id}</guid>"];

            case 'server.create_session':
                return ["{$ok}<id>sess-".base64_decode((string) $op->data->user_ip)."-{$op->login}</id>"];

            case 'webspace.get':
                if (! isset($op->filter->name)) {
                    return array_map(fn (string $name, array $ws) => "{$ok}<id>1</id><data><gen_info><name>{$name}</name><owner-id>{$ws['owner']}</owner-id><status>{$ws['status']}</status></gen_info>"
                        ."<hosting><vrt_hst><property><name>ftp_login</name><value>{$ws['ftp_login']}</value></property></vrt_hst></hosting>"
                        .'<subscriptions><subscription><locked>false</locked><synchronized>true</synchronized><plan><plan-guid>'.($this->pleskPlans[$ws['plan']] ?? '').'</plan-guid></plan></subscription></subscriptions></data>',
                        array_keys($this->pleskWebspaces), $this->pleskWebspaces) ?: $notFound;
                }
                $ws = $this->pleskWebspaces[(string) $op->filter->name] ?? null;

                if (! $ws) {
                    return $notFound;
                }
                $data = "<gen_info><name>{$op->filter->name}</name><owner-id>{$ws['owner']}</owner-id><status>{$ws['status']}</status><real_size>".($ws['real_size'] ?? 0).'</real_size></gen_info>';
                if (isset($op->dataset->limits)) {
                    $data .= '<limits><overuse>normal</overuse><limit><name>max_traffic</name><value>'.($ws['max_traffic'] ?? -1).'</value></limit><limit><name>disk_space</name><value>'.($ws['disk_space'] ?? -1).'</value></limit><limit><name>max_box</name><value>'.($ws['max_box'] ?? -1).'</value></limit><limit><name>max_db</name><value>-1</value></limit></limits>';
                }
                if (isset($op->dataset->stat)) {
                    $data .= '<stat><traffic>'.($ws['traffic'] ?? 0).'</traffic><traffic_prevday>0</traffic_prevday><subdom>0</subdom><box>'.($ws['box'] ?? 0).'</box><db>'.($ws['db'] ?? 0).'</db><sites>1</sites></stat>';
                }
                if (isset($op->dataset->disk_usage)) {
                    $data .= '<disk_usage><httpdocs>0</httpdocs><dbases>'.($ws['dbases'] ?? 0).'</dbases></disk_usage>';
                }

                return ["{$ok}<id>1</id><data>{$data}</data>"];

            case 'webspace.add':
                $name = (string) $op->gen_setup->name;
                $props = [];
                foreach ($op->hosting->vrt_hst->property as $p) {
                    $props[(string) $p->name] = (string) $p->value;
                }
                $this->pleskWebspaces[$name] = [
                    'owner' => (int) $op->gen_setup->{'owner-id'},
                    'status' => 0,
                    'plan' => (string) $op->{'plan-name'},
                    'ftp_login' => $props['ftp_login'] ?? '',
                    'ip' => (string) $op->gen_setup->ip_address,
                ];

                return ["{$ok}<id>1</id><guid>w-1</guid>"];

            case 'webspace.set':
                $name = (string) $op->filter->name;
                if (! isset($this->pleskWebspaces[$name])) {
                    return $notFound;
                }
                if (isset($op->values->gen_setup->status)) {
                    $this->pleskWebspaces[$name]['status'] = (int) $op->values->gen_setup->status;
                }
                foreach ($op->values->hosting->vrt_hst->property ?? [] as $p) {
                    $this->pleskWebspaces[$name][(string) $p->name] = (string) $p->value;
                }

                return [$ok];

            case 'webspace.del':
                unset($this->pleskWebspaces[(string) $op->filter->name]);

                return [$ok];

            case 'dns.get_rec':
                $records = array_filter($this->pleskDnsRecords, fn (array $record) => $record['site_id'] === (int) $op->filter->{'site-id'});

                return array_map(fn (int $id, array $record) => "{$ok}<id>{$id}</id><data><site-id>{$record['site_id']}</site-id><type>{$record['type']}</type><host>{$record['host']}</host><value>{$record['value']}</value><opt></opt></data>",
                    array_keys($records), $records) ?: $notFound;

            case 'dns.add_rec':
                $zone = array_keys($this->pleskWebspaces)[0] ?? 'example.com';
                $this->pleskDnsRecords[] = ['site_id' => (int) $op->{'site-id'}, 'type' => (string) $op->type, 'host' => "{$op->host}.{$zone}.", 'value' => "{$op->value}."];

                return [$ok.'<id>'.count($this->pleskDnsRecords).'</id>'];

            case 'certificate.install':
                $name = (string) $op->name;
                if (isset($this->pleskCertificates[$name])) {
                    return ['<status>error</status><errcode>1007</errcode><errtext>Certificate with such name already exists.</errtext>'];
                }
                $this->pleskCertificates[$name] = ['webspace' => (string) $op->webspace, 'pvt' => (string) $op->content->pvt, 'cert' => (string) $op->content->cert, 'ca' => (string) $op->content->ca];

                return [$ok];

            case 'webspace.switch-subscription':
                $name = (string) $op->filter->name;
                $plan = array_search((string) $op->{'plan-guid'}, $this->pleskPlans, true);
                $this->pleskWebspaces[$name]['plan'] = $plan;

                return [$ok];
        }

        return ["<status>error</status><errcode>1014</errcode><errtext>Unknown operation {$key}</errtext>"];
    }

    /** @param  list<string>  $results */
    private function pleskResult(string $operator, string $operation, array $results): PromiseInterface
    {
        $inner = implode('', array_map(fn ($r) => "<result>{$r}</result>", $results));

        return $this->pleskXml("<{$operator}><{$operation}>{$inner}</{$operation}></{$operator}>");
    }

    private function pleskXml(string $body): PromiseInterface
    {
        return Http::response('<?xml version="1.0" encoding="UTF-8"?><packet version="1.6.9.1">'.$body.'</packet>', 200, ['Content-Type' => 'text/xml']);
    }

    /** @return list<string> "operator.operation" keys called, in order */
    protected function pleskOperations(): array
    {
        return array_column($this->pleskCalls, 0);
    }
}
