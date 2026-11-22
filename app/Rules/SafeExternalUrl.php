<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $rawHost = trim(strtolower($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $rawHost === '') {
            $fail('The :attribute must use an http or https URL.');

            return;
        }

        $host = $this->normalizeHost($rawHost);
        if ($host === null) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $asciiUrl = $host === $rawHost
            ? $value
            : $this->rebuildUrlWithAsciiHost($value, $parts['host'] ?? '', $host);
        if (! filter_var($asciiUrl, FILTER_VALIDATE_URL)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            $fail('The :attribute must point to a public host.');

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                $fail('The :attribute must not point to a private or reserved network.');
            }

            return;
        }

        $ipv4List = gethostbynamel($host);
        if (is_array($ipv4List)) {
            foreach ($ipv4List as $ipv4) {
                if (! $this->isPublicIp($ipv4)) {
                    $fail('The :attribute must not point to a private or reserved network.');

                    return;
                }
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                $ipv6 = $record['ipv6'] ?? null;
                if (is_string($ipv6) && ! $this->isPublicIp($ipv6)) {
                    $fail('The :attribute must not point to a private or reserved network.');

                    return;
                }
            }
        }
    }

    private function normalizeHost(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            return $ascii !== false ? $ascii : null;
        }

        return $host;
    }

    private function rebuildUrlWithAsciiHost(string $url, string $originalHost, string $asciiHost): string
    {
        if ($originalHost === '' || $originalHost === $asciiHost) {
            return $url;
        }

        return str_replace($originalHost, $asciiHost, $url);
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")) {
            $mappedIpv4 = inet_ntop(substr($packed, 12));

            return $mappedIpv4 !== false
                && filter_var($mappedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
