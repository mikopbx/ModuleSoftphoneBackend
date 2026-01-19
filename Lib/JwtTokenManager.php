<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by MikoPBX Team
 *
 */

namespace Modules\ModuleSoftphoneBackend\Lib;

use Exception;

/**
 * JWT Token Manager - handles token creation, validation and refresh
 * Uses HS256 (HMAC SHA-256) for symmetric signing
 */
class JwtTokenManager
{
    private string $secret;
    private int $accessTokenExpiry = 3600;      // 1 hour
    private int $refreshTokenExpiry = 2592000;  // 30 days
    private string $algorithm = 'HS256';

    public function __construct(string $secret = '')
    {
        $this->secret = $secret ?: $this->generateSecret();
    }

    /**
     * Generate a random secret key
     */
    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Create access token
     */
    public function createAccessToken(array $payload): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->accessTokenExpiry;
        $payload['type'] = 'access';

        return $this->encodeToken($payload);
    }

    /**
     * Create refresh token
     */
    public function createRefreshToken(array $payload): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->refreshTokenExpiry;
        $payload['type'] = 'refresh';

        return $this->encodeToken($payload);
    }

    /**
     * Validate and decode token
     */
    public function validateToken(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }
            $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true
            );

            if (!$payload || !is_array($payload)) {
                return null;
            }

            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }
            // Verify signature
            if (!$this->verifySignature($parts[0], $parts[1], $parts[2])) {
                return null;
            }

            return $payload;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check if token is expired
     */
    public function isExpired(string $token): bool
    {
        $payload = $this->validateToken($token);
        if (!$payload) {
            return true;
        }

        return (isset($payload['exp']) && $payload['exp'] < time());
    }

    /**
     * Encode JWT token
     */
    private function encodeToken(array $payload): string
    {
        $header = [
            'alg' => $this->algorithm,
            'typ' => 'JWT'
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signature = $this->createSignature($headerEncoded, $payloadEncoded);

        return "{$headerEncoded}.{$payloadEncoded}.{$signature}";
    }

    /**
     * Create signature
     */
    private function createSignature(string $header, string $payload): string
    {
        $message = "{$header}.{$payload}";
        $signature = hash_hmac('sha256', $message, $this->secret, true);
        return $this->base64UrlEncode($signature);
    }

    /**
     * Verify signature
     */
    private function verifySignature(string $header, string $payload, string $signature): bool
    {
        $message = "{$header}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $message, $this->secret, true);
        $expectedSignature = $this->base64UrlEncode($expectedSignature);
        
        // Debug: Log if signature doesn't match
        if ($expectedSignature !== $signature) {
            error_log("JWT Signature mismatch! Expected: " . substr($expectedSignature, 0, 20) . 
                      "... Got: " . substr($signature, 0, 20) . "... Secret length: " . strlen($this->secret));
        }
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $data): string
    {
        return strtr(rtrim(base64_encode($data), '='), '+/', '-_');
    }

    /**
     * Set access token expiry (in seconds)
     */
    public function setAccessTokenExpiry(int $seconds): void
    {
        $this->accessTokenExpiry = $seconds;
    }

    /**
     * Set refresh token expiry (in seconds)
     */
    public function setRefreshTokenExpiry(int $seconds): void
    {
        $this->refreshTokenExpiry = $seconds;
    }

    /**
     * Get secret key
     */
    public function getSecret(): string
    {
        return $this->secret;
    }
}

