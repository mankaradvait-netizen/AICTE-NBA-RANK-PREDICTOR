<?php

declare(strict_types=1);

namespace App\Security;

use OTPHP\TOTP;
use InvalidArgumentException;
use RuntimeException;

class MfaService
{
    /** @var non-empty-string */
    private readonly string $issuer;

    public function __construct(
        private readonly CryptoService $cryptoService,
        string $issuer = 'NIRF Deterministic Platform'
    ) {
        if ($issuer === '') {
            throw new InvalidArgumentException('MFA issuer must not be empty.');
        }
        $this->issuer = $issuer;
    }

    /**
     * Generates a new TOTP secret and provisioning URI
     * @return array{secret: string, provisioning_uri: string, encrypted_secret: string}
     */
    public function enrollUser(string $userEmail): array
    {
        if ($userEmail === '') {
            throw new InvalidArgumentException('MFA user label must not be empty.');
        }

        $totp = TOTP::generate();
        $totp->setLabel($userEmail);
        $totp->setIssuer($this->issuer);

        $secret = $totp->getSecret();
        $provisioningUri = $totp->getProvisioningUri();

        // Encrypt TOTP secret before storing in database
        $encContainer = $this->cryptoService->encrypt($secret);
        $serialized = json_encode($encContainer);
        if ($serialized === false) {
            throw new RuntimeException("Failed to serialize encrypted TOTP secret.");
        }

        return [
            'secret' => $secret,
            'provisioning_uri' => $provisioningUri,
            'encrypted_secret' => $serialized,
        ];
    }

    /**
     * Verifies a 6-digit TOTP code against the encrypted secret
     */
    public function verifyCode(string $encryptedSecretJson, string $code): bool
    {
        if ($code === '' || !preg_match('/^\d{6}$/D', $code)) {
            return false;
        }

        $container = json_decode($encryptedSecretJson, true);
        if (!is_array($container) || !isset($container['encrypted_payload'])) {
            return false;
        }

        try {
            $secret = $this->cryptoService->decrypt(
                $container['encrypted_payload'],
                $container['iv'],
                $container['auth_tag'] ?? null,
                $container['payload_hmac'] ?? null,
                $container['wrapped_dek'],
                $container['payload_sha256']
            );
        } catch (\Throwable) {
            return false;
        }

        if ($secret === '') {
            return false;
        }

        $totp = TOTP::createFromSecret($secret);
        // Allows 1 window leeway (±30 seconds) for minor clock skew
        return $totp->verify($code, null, 1);
    }
}
