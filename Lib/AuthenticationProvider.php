<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by MikoPBX Team
 *
 */

namespace Modules\ModuleSoftphoneBackend\Lib;

/**
 * Authentication Provider - handles Bearer token extraction and validation
 * Follows RFC 6750 Bearer Token Usage specification
 */
class AuthenticationProvider
{
    private JwtTokenManager $tokenManager;
    private array $credentials = [];

    public function __construct(JwtTokenManager $tokenManager)
    {
        $this->tokenManager = $tokenManager;
    }

    /**
     * Extract Bearer token from Authorization header
     * Format: Authorization: Bearer {token}
     */
    public function extractBearerToken(array $headers = []): ?string
    {
        // Try to get from Authorization header first
        if (!empty($headers['Authorization'])) {
            return $this->parseBearerHeader($headers['Authorization']);
        }

        // Fallback to $_SERVER['HTTP_AUTHORIZATION'] if headers array is empty
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return $this->parseBearerHeader($_SERVER['HTTP_AUTHORIZATION']);
        }

        return null;
    }

    /**
     * Parse Authorization header
     */
    private function parseBearerHeader(string $authHeader): ?string
    {
        $parts = explode(' ', trim($authHeader));

        if (count($parts) === 2 && strtolower($parts[0]) === 'bearer') {
            $token = trim($parts[1]);
            if (!empty($token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Authenticate request using Bearer token
     */
    public function authenticate(array $headers = []): bool
    {
        $token = $this->extractBearerToken($headers);
        if (!$token) {
            $this->credentials = [];
            return false;
        }
        $payload = $this->tokenManager->validateToken($token);
        if (!$payload) {
            $this->credentials = [];
            return false;
        }

        $this->credentials = $payload;
        return true;
    }

    /**
     * Get authenticated credentials
     */
    public function getCredentials(): array
    {
        return $this->credentials;
    }

    /**
     * Get user ID from credentials
     */
    public function getUserId(): ?int
    {
        return isset($this->credentials['sub']) ? intval($this->credentials['sub']) : null;
    }

    /**
     * Get user role from credentials
     */
    public function getUserRole(): ?string
    {
        return $this->credentials['role'] ?? null;
    }

    /**
     * Check if user has specific role
     */
    public function hasRole(string $role): bool
    {
        return ($this->getUserRole() === $role);
    }

    /**
     * Get token type from credentials
     */
    public function getTokenType(): ?string
    {
        return $this->credentials['type'] ?? null;
    }

    /**
     * Check if token is access token
     */
    public function isAccessToken(): bool
    {
        return ($this->getTokenType() === 'access');
    }

    /**
     * Check if token is refresh token
     */
    public function isRefreshToken(): bool
    {
        return ($this->getTokenType() === 'refresh');
    }

    /**
     * Get all custom claims
     */
    public function getClaims(): array
    {
        return $this->credentials;
    }
}

