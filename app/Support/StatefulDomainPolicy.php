<?php

namespace App\Support;

final class StatefulDomainPolicy
{
    /** Validate Sanctum's effective array, without changing it or matching requests. */
    public static function isValid(mixed $domains): bool
    {
        if (! is_array($domains)) {
            return false;
        }
        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                return false;
            }
            // Sanctum trims entries and ignores empty values. Preserve that representation.
            $domain = trim($domain);
            if ($domain === '') {
                continue;
            }
            // Preserve the explicit IPv6 literal present in the repository defaults.
            if (filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                continue;
            }
            if (! preg_match('/\A([a-zA-Z0-9.-]+)(?::([0-9]{1,5}))?\z/', $domain, $parts)) {
                return false;
            }
            if (isset($parts[2]) && ((int) $parts[2] < 1 || (int) $parts[2] > 65535)) {
                return false;
            }
            $host = $parts[1];
            if (preg_match('/\A[0-9.]+\z/', $host)) {
                if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return false;
                }
            } elseif (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                return false;
            }
        }

        return true;
    }
}
