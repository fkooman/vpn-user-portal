<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2022, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal;

use DateTimeImmutable;
use Vpn\Portal\Exception\ConnectionManagerException;
use Vpn\Portal\OpenVpn\ClientConfig as OpenVpnClientConfig;
use Vpn\Portal\WireGuard\ClientConfig as WireGuardClientConfig;
use Vpn\Portal\WireGuard\KeyPair;

/**
 * List, add and remove connections.
 */
class ConnectionManager
{
    /**
     * Used to indicate the certificate/peer should NOT be deleted, e.g. when
     * an account is disabled.
     */
    public const DO_NOT_DELETE = 1;

    protected RandomInterface $random;
    private Config $config;
    private Storage $storage;
    private VpnDaemon $vpnDaemon;
    private LoggerInterface $logger;
    private DateTimeImmutable $dateTime;

    public function __construct(Config $config, VpnDaemon $vpnDaemon, Storage $storage, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->vpnDaemon = $vpnDaemon;
        $this->logger = $logger;
        $this->random = new Random();
        $this->dateTime = Dt::get();
    }

    /**
     * @return array<string,array<array{user_id:string,connection_id:string,display_name:string,ip_list:array<string>,vpn_proto:string}>>
     */
    public function get(): array
    {
        $connectionList = [];
        // keep the record of all nodeUrls we talked to so we only hit them
        // once... multiple profiles can have the same nodeUrl if the run
        // on the same machine/VM
        foreach ($this->config->profileConfigList() as $profileConfig) {
            $profileId = $profileConfig->profileId();
            $connectionList[$profileId] = [];

            if ($profileConfig->oSupport()) {
                // OpenVPN
                $oCertListByProfileId = $this->storage->oCertListByProfileId($profileId, Storage::INCLUDE_EXPIRED);

                $oConnectionList = [];
                for ($i = 0; $i < $profileConfig->nodeCount(); ++$i) {
                    $oConnectionList = array_merge($oConnectionList, $this->vpnDaemon->oConnectionList($profileConfig->nodeUrl($i)));
                }

                foreach ($oCertListByProfileId as $certInfo) {
                    if (\array_key_exists($certInfo['common_name'], $oConnectionList)) {
                        // found it!
                        $commonName = $certInfo['common_name'];
                        $connectionList[$profileId][] = [
                            'user_id' => $certInfo['user_id'],
                            'connection_id' => $certInfo['common_name'],
                            'display_name' => $certInfo['display_name'],
                            'ip_list' => [$oConnectionList[$commonName]['ip_four'], $oConnectionList[$commonName]['ip_six']],
                            'vpn_proto' => 'openvpn',
                        ];
                    }
                }
            }

            if ($profileConfig->wSupport()) {
                // WireGuard
                $wPeerListByProfileId = $this->storage->wPeerListByProfileId($profileId, Storage::INCLUDE_EXPIRED);
                $wPeerList = [];
                for ($i = 0; $i < $profileConfig->nodeCount(); ++$i) {
                    $wPeerList = array_merge($wPeerList, $this->vpnDaemon->wPeerList($profileConfig->nodeUrl($i), false));
                }

                foreach ($wPeerListByProfileId as $peerInfo) {
                    if (\array_key_exists($peerInfo['public_key'], $wPeerList)) {
                        // found it!
                        $connectionList[$profileId][] = [
                            'user_id' => $peerInfo['user_id'],
                            'connection_id' => $peerInfo['public_key'],
                            'display_name' => $peerInfo['display_name'],
                            'ip_list' => [$peerInfo['ip_four'], $peerInfo['ip_six']],
                            'vpn_proto' => 'wireguard',
                        ];
                    }
                }
            }
        }

        return $connectionList;
    }

    /**
     * Remove and disconnect all VPN clients connected with this
     * OAuth authorization ("auth_key"). This works because VPN
     * clients are not allowed to be connected more than once.
     * In the normal situation there is only 1 active connection
     * when "/disconnect" is called, but in case a previous
     * "/disconnect" was never sent, e.g. because the client
     * crashed this also functions as a "cleanup" of sorts.
     */
    public function disconnectByAuthKey(string $authKey): void
    {
        // OpenVPN
        foreach ($this->storage->oCertListByAuthKey($authKey) as $oCertInfo) {
            $this->disconnect(
                $oCertInfo['user_id'],
                $oCertInfo['profile_id'],
                $oCertInfo['common_name']
            );
        }

        // WireGuard
        foreach ($this->storage->wPeerListByAuthKey($authKey) as $wPeerInfo) {
            $this->disconnect(
                $wPeerInfo['user_id'],
                $wPeerInfo['profile_id'],
                $wPeerInfo['public_key']
            );
        }
    }

    public function disconnectByUserId(string $userId, int $optionFlags = 0): void
    {
        // OpenVPN
        foreach ($this->storage->oCertListByUserId($userId) as $oCertInfo) {
            $this->disconnect(
                $userId,
                $oCertInfo['profile_id'],
                $oCertInfo['common_name'],
                $optionFlags
            );
        }

        // WireGuard
        foreach ($this->storage->wPeerListByUserId($userId) as $wPeerInfo) {
            $this->disconnect(
                $userId,
                $wPeerInfo['profile_id'],
                $wPeerInfo['public_key'],
                $optionFlags
            );
        }
    }

    public function connect(ServerInfo $serverInfo, string $userId, string $profileId, string $useProto, string $displayName, DateTimeImmutable $expiresAt, bool $tcpOnly, ?string $publicKey, ?string $authKey): ClientConfigInterface
    {
        if (!$this->config->hasProfile($profileId)) {
            throw new ConnectionManagerException('profile "'.$profileId.'" does not exist');
        }
        $profileConfig = $this->config->profileConfig($profileId);

        // try to find a usable node to connect to, loop over all of them in a
        // random order until we find one that responds, this algorithm can use
        // some tweaking, e.g. consider the load of a node instead of just
        // checking whether it is up
        $nodeNumber = null;
        $nodeList = range(0, $profileConfig->nodeCount() - 1);
        shuffle($nodeList);
        foreach ($nodeList as $i) {
            if (null !== $this->vpnDaemon->nodeInfo($profileConfig->nodeUrl($i))) {
                $nodeNumber = $i;

                break;
            }
            $this->logger->warning('VPN node "'.$i.'" is not usable');
        }
        if (null === $nodeNumber) {
            throw new ConnectionManagerException('unable to find acceptable VPN node');
        }

        if ('openvpn' === $useProto && $profileConfig->oSupport()) {
            $commonName = Base64::encode($this->random->get(32));
            $certInfo = $serverInfo->ca()->clientCert($commonName, $profileConfig->profileId(), $expiresAt);
            $this->storage->oCertAdd(
                $userId,
                $profileId,
                $commonName,
                $displayName,
                $this->dateTime,
                $expiresAt,
                $authKey
            );

            // this thing can throw an ClientConfigException!
            return new OpenVpnClientConfig(
                $nodeNumber,
                $profileConfig,
                $serverInfo->ca()->caCert(),
                $serverInfo->tlsCrypt(),
                $certInfo,
                $tcpOnly
            );
        }

        if ('wireguard' === $useProto && $profileConfig->wSupport()) {
            // WireGuard

            // make sure the node registered their public key with us
            if (null === $serverPublicKey = $serverInfo->publicKey($nodeNumber)) {
                throw new ConnectionManagerException(sprintf('node "%d" did not yet register their WireGuard public key', $nodeNumber));
            }

            $privateKey = null;
            if (null === $publicKey) {
                $keyPair = KeyPair::generate();
                $privateKey = $keyPair['secret_key'];
                $publicKey = $keyPair['public_key'];
            }

            // XXX this call can throw a ConnectionManagerException!
            [$ipFour, $ipSix] = $this->getIpAddress($profileConfig, $nodeNumber);

            // XXX we MUST make sure public_key is unique on this server!!!
            // the DB enforces this, but maybe a better error could be given?
            $this->storage->wPeerAdd($userId, $profileId, $displayName, $publicKey, $ipFour, $ipSix, $this->dateTime, $expiresAt, $authKey);
            $this->vpnDaemon->wPeerAdd($profileConfig->nodeUrl($nodeNumber), $publicKey, $ipFour, $ipSix);
            $this->storage->clientConnect($userId, $profileId, 'wireguard', $publicKey, $ipFour, $ipSix, new DateTimeImmutable());

            $this->logger->info(
                $this->logMessage('CONNECT', $userId, $profileId, $publicKey, $ipFour, $ipSix)
            );

            return new WireGuardClientConfig(
                $nodeNumber,
                $profileConfig,
                $privateKey,
                $ipFour,
                $ipSix,
                $serverPublicKey,
                $this->config->wireGuardConfig()->listenPort()
            );
        }

        throw new ConnectionManagerException(sprintf('unsupported protocol "%s" for profile "%s"', $useProto, $profileId));
    }

    public function disconnect(string $userId, string $profileId, string $connectionId, int $optionFlags = 0): void
    {
        $vpnProto = $this->storage->vpnProto($userId, $profileId, $connectionId);
        if (null === $vpnProto) {
            // the connectionId did not exist
            return;
        }

        if (!$this->config->hasProfile($profileId)) {
            // profile does not exist (anymore)
            // try to delete connectionId anyway if we are not prevented...
            // XXX why could we possibly be prevented here? the profile is no longer there... I guess we are not
            if (0 === (self::DO_NOT_DELETE & $optionFlags)) {
                if ('wireguard' === $vpnProto) {
                    $this->storage->oCertDelete($userId, $connectionId);
                }
                if ('openvpn' === $vpnProto) {
                    $this->storage->wPeerRemove($userId, $connectionId);
                }
                // if no protocol matches, ignore it
            }

            return;
        }
        $profileConfig = $this->config->profileConfig($profileId);

        for ($i = 0; $i < $profileConfig->nodeCount(); ++$i) {
            if ('openvpn' === $vpnProto) {
                if (0 === (self::DO_NOT_DELETE & $optionFlags)) {
                    $this->storage->oCertDelete($userId, $connectionId);
                }
                $this->vpnDaemon->oDisconnectClient($profileConfig->nodeUrl($i), $connectionId);
            }

            if ('wireguard' === $vpnProto) {
                if (0 === (self::DO_NOT_DELETE & $optionFlags)) {
                    $this->storage->wPeerRemove($userId, $connectionId);
                }
                if (null !== $peerInfo = $this->vpnDaemon->wPeerRemove($profileConfig->nodeUrl($i), $connectionId)) {
                    // XXX what if peer was not connected/registered anywhere?
                    // peer was connected to this node, use the information
                    // we got back to call "clientDisconnect"
                    $this->storage->clientDisconnect($userId, $profileId, $connectionId, $peerInfo['bytes_in'], $peerInfo['bytes_out'], new DateTimeImmutable());
                    $this->logger->info(
                        $this->logMessage('DISCONNECT', $userId, $profileId, $connectionId, '_', '_')
                    );
                }
            }
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function getIpAddress(ProfileConfig $profileConfig, int $nodeNumber): array
    {
        // make a list of all allocated IPv4 addresses (the IPv6 address is
        // based on the IPv4 address)
        $allocatedIpFourList = $this->storage->wgGetAllocatedIpFourAddresses();
        $ipFourInRangeList = $profileConfig->wRangeFour($nodeNumber)->clientIpListFour();
        $ipSixInRangeList = $profileConfig->wRangeSix($nodeNumber)->clientIpListSix(\count($ipFourInRangeList));
        foreach ($ipFourInRangeList as $k => $ipFourInRange) {
            if (!\in_array($ipFourInRange, $allocatedIpFourList, true)) {
                return [$ipFourInRange, $ipSixInRangeList[$k]];
            }
        }

        throw new ConnectionManagerException('no free IP address');
    }

    private function logMessage(string $eventType, string $userId, string $profileId, string $connectionId, string $ipFour, string $ipSix): string
    {
        return str_replace(
            [
                '{{EVENT_TYPE}}',
                '{{USER_ID}}',
                '{{PROFILE_ID}}',
                '{{CONNECTION_ID}}',
                '{{IP_FOUR}}',
                '{{IP_SIX}}',
            ],
            [
                $eventType,
                $userId,
                $profileId,
                $connectionId,
                $ipFour,
                $ipSix,
            ],
            $this->config->connectionLogFormat()
        );
    }
}
