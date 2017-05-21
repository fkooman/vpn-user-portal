<?php
/**
 *  Copyright (C) 2017 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SURFnet\VPN\Portal;

use fkooman\OAuth\Client\Http\HttpClientInterface;
use fkooman\OAuth\Client\Http\Request;
use ParagonIE\ConstantTime\Base64;
use RuntimeException;

class ForeignKeyListFetcher
{
    /** @var string */
    private $filePath;

    /**
     * @param string $filePath
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * @param HttpClientInterface $httpClient
     * @param string              $discoveryUrl
     * @param string              $encodedPublicKey
     */
    public function update(HttpClientInterface $httpClient, $discoveryUrl, $encodedPublicKey)
    {
        $publicKey = Base64::decode($encodedPublicKey);
        $discoverySignatureUrl = sprintf('%s.sig', $discoveryUrl);

        $discoveryResponse = $this->httpGet($httpClient, $discoveryUrl);
        $discoverySignatureResponse = $this->httpGet($httpClient, $discoverySignatureUrl);

        $discoverySignature = Base64::decode($discoverySignatureResponse->getBody());
        $discoveryBody = $discoveryResponse->getBody();

        if (!\Sodium\crypto_sign_verify_detached($discoverySignature, $discoveryBody, $publicKey)) {
            throw new RuntimeException('unable to verify signature');
        }

        // check if we already have a file from a previous run
        $seq = 0;
        if (false !== $fileContent = @file_get_contents($this->filePath)) {
            // extract the "seq" field to see if we got a newer version
            $jsonData = self::jsonDecode($fileContent);
            $seq = (int) $jsonData['seq'];
        }

        $discoveryData = $discoveryResponse->json();
        if ($discoveryData['seq'] < $seq) {
            throw new RuntimeException('rollback, this is really unexpected!');
        }

        // all fine, write file
        if (false === @file_put_contents($this->filePath, $discoveryBody)) {
            throw new RuntimeException(sprintf('unable to write file "%s"', $this->filePath));
        }
    }

    /**
     * @return array
     */
    public function extract()
    {
        if (false === $fileContent = @file_get_contents($this->filePath)) {
            return [];
        }

        $jsonData = self::jsonDecode($fileContent);

        $entryList = [];
        foreach ($jsonData['instances'] as $instance) {
            $entryList[$instance['base_uri']] = $instance['public_key'];
        }

        return $entryList;
    }

    /**
     * @param HttpClientInterface $httpClient
     * @param string              $requestUrl
     *
     * @return \fkooman\OAuth\Client\Http\Response
     */
    private function httpGet(HttpClientInterface $httpClient, $requestUrl)
    {
        $httpResponse = $httpClient->send(Request::get($requestUrl));
        if (!$httpResponse->isOkay()) {
            throw new RuntimeException(sprintf('unable to fetch "%s"', $requestUrl));
        }

        return $httpResponse;
    }

    /**
     * @param string
     *
     * @return array
     */
    private static function jsonDecode($jsonText)
    {
        if (null === $jsonData = json_decode($jsonText, true)) {
            throw new RuntimeException('unable to decode JSON');
        }

        return $jsonData;
    }
}
