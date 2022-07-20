<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2022, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal;

interface ConnectionHookInterface
{
    public function connect(string $userId, string $profileId, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp): void;

    public function disconnect(string $userId, string $profileId, string $connectionId, string $ipFour, string $ipSix): void;
}
