<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use InvalidArgumentException;

/**
 * SSRF guard for the flow builder's `webhook` node.
 *
 * An academy can type any URL into the graph editor, so this is attacker-
 * controlled input pointed at our own network. HTTPS only, no private / loopback
 * / link-local ranges, and no cloud metadata endpoints.
 *
 * DNS is resolved here, but note the caveat in docs/12 §6: a rebinding attack
 * can still flip the answer between this check and the socket connect. The HTTP
 * client must therefore pin the resolved address — see resolvedIps().
 *
 * @see docs/12-security-and-compliance.md §6
 */
final class SafeUrl
{
    /** RFC1918, loopback, link-local, CGNAT, benchmarking, documentation, multicast. */
    private const BLOCKED_V4_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const BLOCKED_V6_CIDRS = [
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /** Well-known instance metadata services across the major clouds. */
    private const METADATA_HOSTS = [
        'metadata.google.internal',
        'metadata.goog',
        'instance-data',
        'metadata.azure.com',
        'metadata.packet.net',
    ];

    private const ALLOWED_PORTS = [443];

    /**
     * @param  array<int, string>  $ips
     */
    private function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly array $ips,
    ) {}

    /**
     * @throws InvalidArgumentException when the URL is not safe to call
     */
    public static function validate(string $url): self
    {
        $url = trim($url);

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidArgumentException('The webhook URL could not be parsed.');
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            throw new InvalidArgumentException('Only https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credentials in the webhook URL are not allowed.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new InvalidArgumentException('Only port 443 is allowed for webhook URLs.');
        }

        $host = mb_strtolower(trim($parts['host'], '[]'));

        if ($host === '' || self::isBlockedHostname($host)) {
            throw new InvalidArgumentException('This webhook host is not allowed.');
        }

        $ips = self::resolve($host);

        if ($ips === []) {
            throw new InvalidArgumentException('The webhook host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new InvalidArgumentException('The webhook host resolves to a non-public address.');
            }
        }

        return new self($url, $host, $ips);
    }

    public static function isSafe(string $url): bool
    {
        try {
            self::validate($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Addresses the caller should pin the connection to, so a second DNS lookup
     * inside cURL cannot be steered somewhere else.
     *
     * @return array<int, string>
     */
    public function resolvedIps(): array
    {
        return $this->ips;
    }

    /**
     * cURL `--resolve` entries for the validated host.
     *
     * @return array<int, string>
     */
    public function curlResolveEntries(int $port = 443): array
    {
        return array_map(fn (string $ip): string => "{$this->host}:{$port}:{$ip}", $this->ips);
    }

    private static function isBlockedHostname(string $host): bool
    {
        if (in_array($host, self::METADATA_HOSTS, true)) {
            return true;
        }

        // *.internal / *.local / bare hostnames never point at the public internet.
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home.arpa');
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = gethostbynamel($host);

        if (is_array($v4)) {
            $ips = $v4;
        }

        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if ($ip === '169.254.169.254') {
                return true;
            }

            foreach (self::BLOCKED_V4_CIDRS as $cidr) {
                if (self::ipv4InCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6_CIDRS as $cidr) {
                if (self::ipv6InCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }
}
