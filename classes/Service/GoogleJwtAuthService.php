<?php

namespace GSheetsImport\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopException;

class GoogleJwtAuthService
{
    private const TOKEN_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    /**
     * Generate an OAuth access token using a Service Account JSON file.
     */
    public function getAccessToken(string $credentialPath): string
    {
        if (!is_file($credentialPath)) {
            throw new PrestaShopException('Google credential file not found.');
        }

        $content = file_get_contents($credentialPath);
        if ($content === false) {
            throw new PrestaShopException('Unable to read Google credential file.');
        }

        $credentials = json_decode($content, true);
        if (
            !is_array($credentials) ||
            empty($credentials['client_email']) ||
            empty($credentials['private_key']) ||
            empty($credentials['token_uri'])
        ) {
            throw new PrestaShopException('Invalid Google credential file.');
        }

        $jwt = $this->buildJwt(
            (string) $credentials['client_email'],
            (string) $credentials['private_key'],
            (string) $credentials['token_uri']
        );

        $response = $this->postTokenRequest((string) $credentials['token_uri'], $jwt);

        if (empty($response['access_token'])) {
            throw new PrestaShopException('Google token response does not contain an access token.');
        }

        return (string) $response['access_token'];
    }

    /**
     * Build a signed JWT for OAuth 2.0 Service Account flow.
     */
    private function buildJwt(string $clientEmail, string $privateKey, string $tokenUri): string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $now = time();

        $claims = [
            'iss' => $clientEmail,
            'scope' => self::TOKEN_SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedClaims = $this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $unsignedToken = $encodedHeader . '.' . $encodedClaims;

        $signature = '';
        $result = openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($result !== true) {
            throw new PrestaShopException('Unable to sign Google JWT token.');
        }

        return $unsignedToken . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Exchange JWT for an OAuth access token.
     */
    private function postTokenRequest(string $tokenUri, string $jwt): array
    {
        if (!function_exists('curl_init')) {
            throw new PrestaShopException('cURL extension is required.');
        }

        $ch = curl_init($tokenUri);

        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new PrestaShopException('Google token request failed: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);
        if ($httpCode >= 400) {
            $message = isset($decoded['error_description']) ? (string) $decoded['error_description'] : 'Google token endpoint returned HTTP ' . $httpCode;
            throw new PrestaShopException($message);
        }

        if (!is_array($decoded)) {
            throw new PrestaShopException('Invalid JSON response from Google token endpoint.');
        }

        return $decoded;
    }

    /**
     * Base64 URL-safe encoding without padding.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}