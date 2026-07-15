<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Support;

defined('ABSPATH') || exit;

final class Endpoints
{
    private const DEFAULT_DOMAIN = 'notrouble.com';

    private string $domain;

    public function __construct(?string $domain = null)
    {
        $this->domain = $domain ?? self::resolveDomain();
    }

    private static function resolveDomain(): string
    {
        $domain = defined('PACK_AND_GO_NOTROUBLE_DOMAIN') ? (string) constant('PACK_AND_GO_NOTROUBLE_DOMAIN') : self::DEFAULT_DOMAIN;

        /** @var string $filtered */
        $filtered = apply_filters('pack_and_go_notrouble_domain', $domain);

        return $filtered !== '' ? $filtered : self::DEFAULT_DOMAIN;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function issuer(): string
    {
        return 'https://' . $this->domain;
    }

    public function registrationUrl(): string
    {
        return 'https://api.' . $this->domain . '/oauth/clients';
    }

    public function authorizeUrl(): string
    {
        return 'https://my.' . $this->domain . '/oauth/authorize';
    }

    public function tokenUrl(): string
    {
        return 'https://my.' . $this->domain . '/oauth/token';
    }

    public function apiBase(): string
    {
        return 'https://api.' . $this->domain . '/v1';
    }
}
