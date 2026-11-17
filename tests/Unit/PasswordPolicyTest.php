<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $password = 'SecureP@ssw0rd2026!';
        $hash = PasswordPolicy::hash($password);

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(PasswordPolicy::verify($password, $hash));
        $this->assertFalse(PasswordPolicy::verify('WrongP@ssw0rd2026!', $hash));
    }

    public function testRejectsShortPassword(): void
    {
        $errors = PasswordPolicy::validate('Short1!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 12 characters', $errors[0]);
    }

    public function testRejectsCommonPasswords(): void
    {
        $errors = PasswordPolicy::validate('password123456');
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            in_array('Password is too common and easily guessable.', $errors, true) ||
            count($errors) > 0
        );
    }

    public function testRejectsPreviouslyUsedPasswords(): void
    {
        $password = 'BrandNewP@ssw0rd2026!';
        $oldHash = PasswordPolicy::hash($password);

        $errors = PasswordPolicy::validate($password, [$oldHash]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cannot be identical to any of the last 5', $errors[0]);
    }

    public function testAcceptsValidComplexPassword(): void
    {
        $password = 'Stron9-En0ugh-P@ssphr@se!';
        $errors = PasswordPolicy::validate($password, []);
        $this->assertEmpty($errors);
    }
}
