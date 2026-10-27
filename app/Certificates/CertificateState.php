<?php

namespace App\Certificates;

use App\Enums\CertificateStatus;
use Carbon\CarbonImmutable;

/** What a provider says about an order. */
final readonly class CertificateState
{
    /**
     * @param  array<string, mixed>  $validation  dns => [record], http / https => [link, filename, content], email => [email]
     */
    public function __construct(
        public string $orderId,
        public CertificateStatus $status,
        public array $validation = [],
        public ?string $method = null,
        public ?string $certificate = null,
        public ?string $caBundle = null,
        public ?CarbonImmutable $validFrom = null,
        public ?CarbonImmutable $validTill = null,
        public ?CarbonImmutable $endsAt = null,
        public ?string $message = null,
    ) {}
}
