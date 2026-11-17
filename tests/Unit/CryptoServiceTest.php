<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\CryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CryptoServiceTest extends TestCase
{
    private string $masterKeyHex;

    protected function setUp(): void
    {
        $this->masterKeyHex = bin2hex(random_bytes(32)); // 64 hex chars
    }

    public function testGcmEnvelopeEncryptionAndDecryption(): void
    {
        $crypto = new CryptoService($this->masterKeyHex, 'key-2026-v1', 'gcm');
        $payload = json_encode(['salary' => 1250000, 'capex' => 9500000]);

        $container = $crypto->encrypt($payload);

        $this->assertNotEmpty($container['encrypted_payload']);
        $this->assertNotEmpty($container['iv']);
        $this->assertNotEmpty($container['auth_tag']);
        $this->assertNull($container['payload_hmac']);
        $this->assertEquals(hash('sha256', $payload), $container['payload_sha256']);

        $decrypted = $crypto->decrypt(
            $container['encrypted_payload'],
            $container['iv'],
            $container['auth_tag'],
            null,
            $container['wrapped_dek'],
            $container['payload_sha256']
        );

        $this->assertEquals($payload, $decrypted);
    }

    public function testGcmTamperedCiphertextThrowsException(): void
    {
        $crypto = new CryptoService($this->masterKeyHex, 'key-2026-v1', 'gcm');
        $payload = 'Confidential NIRF Institutional Ledger';
        $container = $crypto->encrypt($payload);

        // Tamper ciphertext
        $tamperedCiphertext = $container['encrypted_payload'];
        $tamperedCiphertext[0] = chr(ord($tamperedCiphertext[0]) ^ 0xFF);

        $this->expectException(RuntimeException::class);
        $crypto->decrypt(
            $tamperedCiphertext,
            $container['iv'],
            $container['auth_tag'],
            null,
            $container['wrapped_dek'],
            $container['payload_sha256']
        );
    }

    public function testCbcHmacCompatibilityMode(): void
    {
        $crypto = new CryptoService($this->masterKeyHex, 'key-2026-v1', 'cbc_hmac');
        $payload = json_encode(['faculty_salaries' => [450000, 750000, 1200000]]);

        $container = $crypto->encrypt($payload);

        $this->assertNotEmpty($container['encrypted_payload']);
        $this->assertNull($container['auth_tag']);
        $this->assertNotEmpty($container['payload_hmac']);

        $decrypted = $crypto->decrypt(
            $container['encrypted_payload'],
            $container['iv'],
            null,
            $container['payload_hmac'],
            $container['wrapped_dek'],
            $container['payload_sha256']
        );

        $this->assertEquals($payload, $decrypted);
    }

    public function testCbcHmacTamperDetected(): void
    {
        $crypto = new CryptoService($this->masterKeyHex, 'key-2026-v1', 'cbc_hmac');
        $payload = 'Test Payload';
        $container = $crypto->encrypt($payload);

        // Modify HMAC
        $tamperedHmac = $container['payload_hmac'];
        $tamperedHmac[0] = chr(ord($tamperedHmac[0]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');
        $crypto->decrypt(
            $container['encrypted_payload'],
            $container['iv'],
            null,
            $tamperedHmac,
            $container['wrapped_dek'],
            $container['payload_sha256']
        );
    }

    public function testKeyRewrappingForRotation(): void
    {
        $crypto = new CryptoService($this->masterKeyHex, 'key-2026-v1', 'gcm');
        $payload = 'Data for key rotation';
        $container = $crypto->encrypt($payload);

        $newMasterKeyHex = bin2hex(random_bytes(32));
        $rewrappedDek = $crypto->rewrapDek($container['wrapped_dek'], $newMasterKeyHex);

        $newCrypto = new CryptoService($newMasterKeyHex, 'key-2027-v2', 'gcm');
        $decrypted = $newCrypto->decrypt(
            $container['encrypted_payload'],
            $container['iv'],
            $container['auth_tag'],
            null,
            $rewrappedDek,
            $container['payload_sha256']
        );

        $this->assertEquals($payload, $decrypted);
    }

    public function testEncryptedPayloadCanBeRewrappedWithoutChangingCiphertext(): void
    {
        $oldCrypto = new CryptoService($this->masterKeyHex, 'key-2026-v1', 'gcm');
        $payload = 'Persisted payload for transactional key rotation';
        $container = $oldCrypto->encrypt($payload);
        $newMasterKeyHex = bin2hex(random_bytes(32));

        $rewrappedPayload = $oldCrypto->rewrapEncryptedPayload(
            $container['encrypted_payload'],
            $newMasterKeyHex
        );
        $newCrypto = new CryptoService($newMasterKeyHex, 'key-2027-v2', 'gcm');

        $this->assertNotSame($container['encrypted_payload'], $rewrappedPayload);
        $originalBytes = base64_decode($container['encrypted_payload'], true);
        $rewrappedBytes = base64_decode($rewrappedPayload, true);
        $this->assertIsString($originalBytes);
        $this->assertIsString($rewrappedBytes);
        $this->assertSame(substr($originalBytes, 60), substr($rewrappedBytes, 60));
        $this->assertSame(
            $payload,
            $newCrypto->decrypt(
                $rewrappedPayload,
                $container['iv'],
                $container['auth_tag'],
                null,
                '',
                $container['payload_sha256']
            )
        );
    }
}
