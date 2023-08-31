<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Http\Auth;

use Vpn\Portal\Cfg\LdapAuthConfig;
use Vpn\Portal\Exception\LdapClientException;
use Vpn\Portal\Http\Auth\Exception\CredentialValidatorException;
use Vpn\Portal\Http\UserInfo;
use Vpn\Portal\LdapClient;
use Vpn\Portal\LoggerInterface;
use Vpn\Portal\PermissionSourceInterface;

class LdapCredentialValidator implements CredentialValidatorInterface, PermissionSourceInterface
{
    private LdapAuthConfig $ldapAuthConfig;
    private LoggerInterface $logger;
    private LdapClient $ldapClient;

    public function __construct(LdapAuthConfig $ldapAuthConfig, LoggerInterface $logger)
    {
        $this->ldapAuthConfig = $ldapAuthConfig;
        $this->logger = $logger;
        $this->ldapClient = new LdapClient(
            $ldapAuthConfig->ldapUri(),
            $ldapAuthConfig->tlsCa(),
            $ldapAuthConfig->tlsCert(),
            $ldapAuthConfig->tlsKey()
        );
    }

    /**
     * Validate a user's credentials are return the (normalized) internal
     * user ID to use.
     */
    public function validate(string $authUser, string $authPass): UserInfo
    {
        $bindDn = $this->authUserToDn($authUser);

        try {
            $this->ldapClient->bind($bindDn, $authPass);

            // we "normalize" the `userId` by also requesting the
            // `userIdAttribute` from the directory together with the
            // permission attribute(s) in order to be able to uniquely identify
            // the user as LDAP authentication is "case insenstive"
            $userIdAttribute = $this->ldapAuthConfig->userIdAttribute();
            $attributeNameValueList = $this->attributesForDn(
                $bindDn,
                array_merge(
                    [$userIdAttribute],
                    $this->ldapAuthConfig->permissionAttributeList()
                )
            );

            // update userId with the "normalized" value from the LDAP
            // server
            if (!isset($attributeNameValueList[$userIdAttribute][0])) {
                throw new CredentialValidatorException(sprintf('unable to find userIdAttribute (=%s) in LDAP result', $userIdAttribute));
            }
            $userId = $attributeNameValueList[$userIdAttribute][0];

            return new UserInfo(
                $userId,
                AbstractAuthModule::flattenPermissionList($attributeNameValueList, $this->ldapAuthConfig->permissionAttributeList())
            );
        } catch (LdapClientException $e) {
            // convert LDAP errors into `CredentialValidatorException`
            throw new CredentialValidatorException($e->getMessage());
        }
    }

    /**
     * Get current attributes for users directly from the source.
     *
     * If no attributes are available, or the user no longer exists, an empty
     * array is returned.
     *
     * @return array<string>
     */
    public function attributesForUser(string $userId): array
    {
        return AbstractAuthModule::flattenPermissionList(
            $this->attributesForDn(
                $this->authUserToDn($userId),
                $this->ldapAuthConfig->permissionAttributeList()
            )
        );
    }

    private function authUserToDn(string $authUser): string
    {
        try {
            // add "realm" after user name if none is specified
            if (null !== $addRealm = $this->ldapAuthConfig->addRealm()) {
                if (false === strpos($authUser, '@')) {
                    $authUser .= '@'.$addRealm;
                }
            }

            if (null !== $bindDnTemplate = $this->ldapAuthConfig->bindDnTemplate()) {
                // we have a bind DN template to bind to the LDAP with the user's
                // provided "Username", so use that
                return str_replace('{{UID}}', LdapClient::escapeDn($authUser), $bindDnTemplate);
            }

            // Do (anonymous) LDAP bind to find the DN based on userFilterTemplate
            $this->ldapClient->bind($this->ldapAuthConfig->searchBindDn(), $this->ldapAuthConfig->searchBindPass());
            $userFilter = str_replace('{{UID}}', LdapClient::escapeFilter($authUser), $this->ldapAuthConfig->userFilterTemplate());
            if (null === $ldapEntries = $this->ldapClient->search($this->ldapAuthConfig->baseDn(), $userFilter)) {
                // XXX better error message, or perhaps return null?!
                throw new CredentialValidatorException(__CLASS__.': user not found');
            }

            return $ldapEntries['dn'];
        } catch (LdapClientException $e) {
            // convert LDAP errors into `CredentialValidatorException`
            throw new CredentialValidatorException($e->getMessage());
        }
    }

    /**
     * Get requested attributes for DN.
     *
     * If no attributes are available, or the user no longer exists, an empty
     * array is returned.
     *
     * @param array<string> $attributeNameList
     *
     * @return array<string,array<string>>
     */
    private function attributesForDn(string $userDn, array $attributeNameList): array
    {
        if (0 === count($attributeNameList)) {
            // no attributes requested
            return [];
        }

        try {
            if (null === $searchResult = $this->ldapClient->search($userDn, null, $attributeNameList)) {
                // no such entry, the user might no longer exist
                return [];
            }

            return $searchResult['result'];
        } catch (LdapClientException $e) {
            // XXX should we log this and return empty array instead?!
            // convert LDAP errors into `CredentialValidatorException`
            throw new CredentialValidatorException($e->getMessage());
        }
    }
}
