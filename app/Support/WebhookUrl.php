<?php

namespace App\Support;

/**
 * Validación anti-SSRF de la URL destino de un webhook. Exige HTTPS y bloquea destinos
 * internos (loopback, rangos privados y reservados) resolviendo el host. Evita que
 * alguien registre un webhook apuntando a la red interna del servidor.
 */
class WebhookUrl
{
    /** Devuelve un mensaje de error si la URL no es válida/segura, o null si está OK. */
    public static function validationError(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'La URL no es válida.';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'La URL debe usar https.';
        }

        // Bloquea destinos internos: se resuelve el host y se rechaza si alguna IP es
        // privada o reservada (localhost, 10.x, 192.168.x, 169.254.x, ::1, fc00::/7, …).
        foreach (self::resolve($parts['host']) as $ip) {
            if (! self::isPublic($ip)) {
                return 'La URL apunta a una dirección interna no permitida.';
            }
        }

        return null;
    }

    public static function isSafe(string $url): bool
    {
        return self::validationError($url) === null;
    }

    /** @return array<int,string> IPs a las que resuelve el host (v4 + v6, best-effort). */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    private static function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
