<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use LC\Portal\CA\VpnCa;
use LC\Portal\Config;
use LC\Portal\FileIO;
use LC\Portal\Http\Auth\NodeAuthModule;
use LC\Portal\Http\JsonResponse;
use LC\Portal\Http\NodeApiModule;
use LC\Portal\Http\Request;
use LC\Portal\Http\Service;
use LC\Portal\ServerConfig;
use LC\Portal\Storage;
use LC\Portal\SysLogger;
use LC\Portal\TlsCrypt;

try {
    $config = Config::fromFile($baseDir.'/config/config.php');
    $service = new Service();
    $service->setAuthModule(
        new NodeAuthModule(
            FileIO::readFile($baseDir.'/config/node.key'),
            'Node API'
        )
    );

    $storage = new Storage(
        new PDO(
            $config->s('Db')->requireString('dbDsn', 'sqlite://'.$baseDir.'/data/db.sqlite'),
            $config->s('Db')->optionalString('dbUser'),
            $config->s('Db')->optionalString('dbPass')
        ),
        $baseDir.'/schema'
    );
    $storage->update();
    $vpnCaPath = $config->requireString('vpnCaPath', '/usr/bin/vpn-ca');
    $ca = new VpnCa($baseDir.'/data/ca', 'EdDSA', $vpnCaPath);

    $service->addModule(
        new NodeApiModule(
            $config,
            $storage,
            new ServerConfig($config, $ca, new TlsCrypt($baseDir.'/data'))
        )
    );
    $request = new Request($_SERVER, $_GET, $_POST);
    $service->run($request)->send();
} catch (Exception $e) {
    $logger = new SysLogger('vpn-user-portal-node-api');
    $logger->error($e->getMessage());
    $response = new JsonResponse(['error' => $e->getMessage()], [], 500);
    $response->send();
}
