<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Laravel's TrustProxies, fed from config('hrm.trusted_proxies') (env HRM_TRUSTED_PROXIES) at request time, so it
 * works with config:cache and can be changed in tests. Empty list = trust no proxy. Only X-Forwarded-For and
 * X-Forwarded-Proto are believed, and only when the connecting address is on the list.
 */
class TrustConfiguredProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies()
    {
        return (array) config('hrm.trusted_proxies', []);
    }
}
