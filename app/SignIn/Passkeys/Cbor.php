<?php

namespace App\SignIn\Passkeys;

/**
 * Just enough CBOR (RFC 8949) to read what a browser sends when a passkey is added: the attestation object and the
 * COSE public key inside it. Definite lengths only (WebAuthn's are); floats and tags are refused.
 */
final class Cbor
{
    private int $offset = 0;

    private function __construct(private readonly string $data) {}

    /** The first item of $data, and in $end where it stopped (the public key is followed by more bytes). */
    public static function decode(string $data, int $start = 0, ?int &$end = null): mixed
    {
        $reader = new self($data);
        $reader->offset = $start;
        $value = $reader->item();
        $end = $reader->offset;

        return $value;
    }

    private function item(): mixed
    {
        $first = ord($this->take(1));
        $major = $first >> 5;
        $length = $this->length($first & 0x1F);

        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2, 3 => $this->take($length),
            4 => array_map(fn () => $this->item(), $length > 0 ? range(1, $length) : []),
            5 => $this->map($length),
            7 => match ($first & 0x1F) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new PasskeyException('Unsupported CBOR value'),
            },
            default => throw new PasskeyException('Unsupported CBOR value'),
        };
    }

    /** @return array<int|string, mixed> */
    private function map(int $length): array
    {
        $map = [];

        for ($i = 0; $i < $length; $i++) {
            $key = $this->item();

            if (! is_int($key) && ! is_string($key)) {
                throw new PasskeyException('Unsupported CBOR map key');
            }

            $map[$key] = $this->item();
        }

        return $map;
    }

    private function length(int $info): int
    {
        return match (true) {
            $info < 24 => $info,
            $info === 24 => ord($this->take(1)),
            $info === 25 => unpack('n', $this->take(2))[1],
            $info === 26 => unpack('N', $this->take(4))[1],
            $info === 27 => unpack('J', $this->take(8))[1],
            default => throw new PasskeyException('Unsupported CBOR length'),
        };
    }

    private function take(int $bytes): string
    {
        if ($bytes < 0 || $this->offset + $bytes > strlen($this->data)) {
            throw new PasskeyException('CBOR data ends too soon');
        }

        $chunk = substr($this->data, $this->offset, $bytes);
        $this->offset += $bytes;

        return $chunk;
    }
}
