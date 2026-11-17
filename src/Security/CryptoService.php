<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use InvalidArgumentException;

/**
 * Defense-in-depth Cryptographic Service:
 * - Default: Envelope encryption using AES-256-GCM with per-record DEK wrapped by KEK.
 * - Compatibility Mode: AES-256-CBC with Encrypt-then-MAC (HMAC-SHA256) via HKDF keys.
 * - Envelope DEK is bundled at the head of the ciphertext container (60 bytes).
 * - Full tamper detection and plaintext SHA-256 verification.
 */
class CryptoService
{
    private const WRAPPED_DEK_LENGTH = 60; // 12-byte IV + 16-byte Tag + 32-byte wrapped DEK
    private string $masterKey;
    private string $keyId;
    private string $cryptoMode;

    public function __construct(
        string $masterKeyHex,
        string $keyId = 'key-2026-v1',
        string $cryptoMode = 'gcm'
    ) {
        if (strlen($masterKeyHex) !== 64) {
            throw new InvalidArgumentException("Master key must be a 64-character hex string (32 bytes / 256 bits).");
        }
        $binKey = hex2bin($masterKeyHex);
        if ($binKey === false) {
            throw new InvalidArgumentException("Invalid hex string provided for master key.");
        }
        $this->masterKey = $binKey;
        $this->keyId = $keyId;
        $this->cryptoMode = strtolower($cryptoMode);
    }

    /**
     * Encrypts payload and returns envelope encryption container
     * @return array{
     *   encrypted_payload: string,
     *   iv: string,
     *   auth_tag: ?string,
     *   payload_hmac: ?string,
     *   wrapped_dek: string,
     *   key_id: string,
     *   payload_sha256: string
     * }
     */
    public function encrypt(string $plaintext): array
    {
        $payloadSha256 = hash('sha256', $plaintext);
        $dek = random_bytes(32); // 256-bit per-record data key

        // Wrap DEK with Master Key (KEK) using AES-256-GCM
        $dekIv = random_bytes(12);
        $dekTag = '';
        $wrappedDekCipher = openssl_encrypt(
            $dek,
            'aes-256-gcm',
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $dekIv,
            $dekTag
        );
        if ($wrappedDekCipher === false) {
            throw new RuntimeException("Failed to wrap data encryption key.");
        }
        $wrappedDek = $dekIv . $dekTag . $wrappedDekCipher; // Exactly 60 bytes

        if ($this->cryptoMode === 'cbc_hmac') {
            // Compatibility mode: HKDF derived keys for AES-256-CBC and HMAC-SHA256
            $encKey = hash_hkdf('sha256', $dek, 32, 'encryption', 'nirf_enc_v1');
            $macKey = hash_hkdf('sha256', $dek, 32, 'authentication', 'nirf_mac_v1');

            $iv = random_bytes(16);
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
            if ($ciphertext === false) {
                throw new RuntimeException("AES-256-CBC encryption failed.");
            }

            // Encrypt-then-MAC over (IV || Ciphertext)
            $hmac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);

            $bundled = $wrappedDek . $ciphertext;

            return [
                'encrypted_payload' => base64_encode($bundled),
                'iv' => base64_encode($iv),
                'auth_tag' => null,
                'payload_hmac' => base64_encode($hmac),
                'wrapped_dek' => base64_encode($wrappedDek),
                'key_id' => $this->keyId,
                'payload_sha256' => $payloadSha256,
            ];
        }

        // Default: AES-256-GCM authenticated encryption
        $iv = random_bytes(12);
        $authTag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $dek,
            OPENSSL_RAW_DATA,
            $iv,
            $authTag
        );
        if ($ciphertext === false) {
            throw new RuntimeException("AES-256-GCM encryption failed.");
        }

        $bundled = $wrappedDek . $ciphertext;

        return [
            'encrypted_payload' => base64_encode($bundled),
            'iv' => base64_encode($iv),
            'auth_tag' => base64_encode($authTag),
            'payload_hmac' => null,
            'wrapped_dek' => base64_encode($wrappedDek),
            'key_id' => $this->keyId,
            'payload_sha256' => $payloadSha256,
        ];
    }

    /**
     * Decrypts envelope container and verifies cryptographic integrity and SHA-256 match
     */
    public function decrypt(
        string $encryptedPayload,
        string $iv,
        ?string $authTag,
        ?string $payloadHmac,
        string $wrappedDek,
        string $expectedSha256
    ): string {
        $cipherBytes = base64_decode($encryptedPayload, true);
        if ($cipherBytes === false) {
            $cipherBytes = $encryptedPayload;
        }

        $ivBytes = base64_decode($iv, true);
        if ($ivBytes === false) {
            $ivBytes = $iv;
        }

        $tagBytes = null;
        if ($authTag !== null) {
            $tagBytes = base64_decode($authTag, true);
            if ($tagBytes === false) {
                $tagBytes = $authTag;
            }
        }

        $hmacBytes = null;
        if ($payloadHmac !== null) {
            $hmacBytes = base64_decode($payloadHmac, true);
            if ($hmacBytes === false) {
                $hmacBytes = $payloadHmac;
            }
        }

        // 1. Extract Wrapped DEK
        $passedWrapped = base64_decode($wrappedDek, true);
        if ($passedWrapped !== false && strlen($passedWrapped) >= self::WRAPPED_DEK_LENGTH) {
            $wrappedBytes = $passedWrapped;
            // If the payload has the bundled prefix, strip it
            if (strlen($cipherBytes) >= self::WRAPPED_DEK_LENGTH) {
                $cipherBytes = substr($cipherBytes, self::WRAPPED_DEK_LENGTH);
            }
        } else {
            // Extract from bundled payload prefix
            if (strlen($cipherBytes) >= self::WRAPPED_DEK_LENGTH) {
                $wrappedBytes = substr($cipherBytes, 0, self::WRAPPED_DEK_LENGTH);
                $cipherBytes = substr($cipherBytes, self::WRAPPED_DEK_LENGTH);
            } else {
                throw new RuntimeException("Malformed wrapped DEK payload.");
            }
        }

        $dekIv = substr($wrappedBytes, 0, 12);
        $dekTag = substr($wrappedBytes, 12, 16);
        $wrappedDekCipher = substr($wrappedBytes, 28);

        $dek = openssl_decrypt(
            $wrappedDekCipher,
            'aes-256-gcm',
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $dekIv,
            $dekTag
        );
        if ($dek === false) {
            throw new RuntimeException("Integrity check failed: unable to unwrap data encryption key.");
        }

        // 2. Decrypt with DEK
        $plaintext = false;
        if ($hmacBytes !== null) {
            // CBC + HMAC mode
            $encKey = hash_hkdf('sha256', $dek, 32, 'encryption', 'nirf_enc_v1');
            $macKey = hash_hkdf('sha256', $dek, 32, 'authentication', 'nirf_mac_v1');

            $expectedHmac = hash_hmac('sha256', $ivBytes . $cipherBytes, $macKey, true);
            if (!hash_equals($expectedHmac, $hmacBytes)) {
                throw new RuntimeException("HMAC verification failed: encrypted payload has been tampered with.");
            }

            $plaintext = openssl_decrypt($cipherBytes, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $ivBytes);
        } else {
            // GCM mode
            if ($tagBytes === null || strlen($tagBytes) !== 16) {
                throw new RuntimeException("Missing or invalid authentication tag for GCM decryption.");
            }
            $plaintext = openssl_decrypt($cipherBytes, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA, $ivBytes, $tagBytes);
        }

        if ($plaintext === false) {
            throw new RuntimeException("Decryption failure or cryptographic authentication tag mismatch.");
        }

        // 3. Verify SHA-256 payload integrity
        $actualSha256 = hash('sha256', $plaintext);
        if (!hash_equals($expectedSha256, $actualSha256)) {
            throw new RuntimeException("Payload integrity violation: decrypted data SHA-256 does not match recorded digest.");
        }

        return $plaintext;
    }

    /**
     * Re-wraps DEK under a new master key during key rotation
     */
    public function rewrapDek(string $wrappedDek, string $newMasterKeyHex): string
    {
        if (strlen($newMasterKeyHex) !== 64) {
            throw new InvalidArgumentException("New master key must be a 64-character hex string.");
        }
        $newMasterKey = hex2bin($newMasterKeyHex);
        if ($newMasterKey === false || strlen($newMasterKey) !== 32) {
            throw new InvalidArgumentException("Invalid new master key.");
        }
        if (hash_equals($this->masterKey, $newMasterKey)) {
            throw new InvalidArgumentException("New master key must differ from the active master key.");
        }

        $wrappedBytes = base64_decode($wrappedDek, true);
        if ($wrappedBytes === false || strlen($wrappedBytes) !== self::WRAPPED_DEK_LENGTH) {
            throw new InvalidArgumentException("Malformed wrapped data encryption key.");
        }

        $dekIv = substr($wrappedBytes, 0, 12);
        $dekTag = substr($wrappedBytes, 12, 16);
        $wrappedDekCipher = substr($wrappedBytes, 28);

        $dek = openssl_decrypt($wrappedDekCipher, 'aes-256-gcm', $this->masterKey, OPENSSL_RAW_DATA, $dekIv, $dekTag);
        if ($dek === false) {
            throw new RuntimeException("Cannot unwrap DEK with existing master key.");
        }

        $newDekIv = random_bytes(12);
        $newDekTag = '';
        $newWrappedCipher = openssl_encrypt($dek, 'aes-256-gcm', $newMasterKey, OPENSSL_RAW_DATA, $newDekIv, $newDekTag);
        if ($newWrappedCipher === false) {
            throw new RuntimeException("Failed to re-wrap data encryption key.");
        }

        return base64_encode($newDekIv . $newDekTag . $newWrappedCipher);
    }

    public function rewrapEncryptedPayload(string $encryptedPayload, string $newMasterKeyHex): string
    {
        $payloadBytes = base64_decode($encryptedPayload, true);
        if ($payloadBytes === false || strlen($payloadBytes) < self::WRAPPED_DEK_LENGTH) {
            throw new InvalidArgumentException("Malformed encrypted payload envelope.");
        }

        $wrappedDek = base64_encode(substr($payloadBytes, 0, self::WRAPPED_DEK_LENGTH));
        $newWrappedDek = base64_decode($this->rewrapDek($wrappedDek, $newMasterKeyHex), true);
        if ($newWrappedDek === false || strlen($newWrappedDek) !== self::WRAPPED_DEK_LENGTH) {
            throw new RuntimeException("Re-wrapped data encryption key is malformed.");
        }

        return base64_encode($newWrappedDek . substr($payloadBytes, self::WRAPPED_DEK_LENGTH));
    }
}
