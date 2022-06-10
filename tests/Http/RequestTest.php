<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2022, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use RangeException;
use Vpn\Portal\Cfg\HttpRequestConfig;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Http\Request;

/**
 * @internal
 * @coversNothing
 */
final class RequestTest extends TestCase
{
    public function testValidate(): void
    {
        $r = new Request(
            [],
            [],
            [
                'xyz' => 'foo',
            ],
            [],
            new HttpRequestConfig([])
        );

        static::expectException(HttpException::class);
        static::expectExceptionMessage('invalid value for "xyz"');
        $r->requirePostParameter('xyz', function ($s): void { throw new RangeException(); });
    }

    public function testNotProxiedRequest(): void
    {
        $r = new Request(
            [
                'REMOTE_ADDR' => '10.10.0.100',
                'SERVER_NAME' => 'the.server.name',
                'SERVER_PORT' => '443',
                'HTTPS' => 'on',
                'HTTP_X_FORWARDED_PROTO' => 'http',
                'HTTP_X_FORWARDED_HOST' => 'not.the.server.name',
                'HTTP_X_FORWARDED_PORT' => '80',
            ],
            [],
            [],
            [],
            new HttpRequestConfig(['proxyList' => ['10.10.0.0/30', '172.16.0.1']])
        );
        static::assertSame('https', $r->getScheme());
        static::assertSame('the.server.name', $r->getServerName());
        static::assertSame(443, $r->getServerPort());
    }

    public function testProxiedRequest(): void
    {
        $r = new Request(
            [
                'REMOTE_ADDR' => '10.10.0.2',
                'SERVER_NAME' => 'internal.server.name',
                'SERVER_PORT' => '80',
                'HTTPS' => 'off',
                'HTTP_X_FORWARDED_PROTO' => 'http',
                'HTTP_X_FORWARDED_HOST' => 'external.server.name',
                'HTTP_X_FORWARDED_PORT' => '8080',
            ],
            [],
            [],
            [],
            new HttpRequestConfig(['proxyList' => ['10.10.0.0/30', '172.16.0.1']])
        );
        static::assertSame('http', $r->getScheme());
        static::assertSame('external.server.name', $r->getServerName());
        static::assertSame(8080, $r->getServerPort());
    }
}
