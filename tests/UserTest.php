<?php
namespace Tests;

use App\User;
use MongoDB\BSON\ObjectId;
use Exception;

class UserTest extends TestCase
{
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User($this->db);
    }

    public function testCreateUser(): void
    {
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!'
        ];

        $userId = $this->user->create($userData);
        $this->assertInstanceOf(ObjectId::class, $userId);

        $createdUser = $this->db->users->findOne(['_id' => $userId]);
        $this->assertNotNull($createdUser);
        $this->assertEquals($userData['name'], $createdUser['name']);
        $this->assertEquals($userData['email'], $createdUser['email']);
        $this->assertTrue(password_verify($userData['password'], $createdUser['password']));
        $this->assertFalse($createdUser['is_verified']);
        $this->assertTrue($createdUser['is_active']);
    }

    public function testCreateUserWithExistingEmail(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Email already registered');

        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!'
        ];

        // Create first user
        $this->user->create($userData);

        // Try to create another user with same email
        $this->user->create($userData);
    }

    public function testAuthenticateUser(): void
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'is_verified' => true
        ];

        $userId = $this->user->create($userData);
        
        // Test successful authentication
        $authenticatedUser = $this->user->authenticate($userData['email'], $userData['password']);
        $this->assertEquals($userId, $authenticatedUser['_id']);

        // Test invalid password
        try {
            $this->user->authenticate($userData['email'], 'wrongpassword');
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Invalid email or password', $e->getMessage());
        }

        // Test non-existent email
        try {
            $this->user->authenticate('nonexistent@example.com', 'password');
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Invalid email or password', $e->getMessage());
        }
    }

    public function testUpdateProfile(): void
    {
        $userData = $this->createTestUser();
        $userId = $userData['_id'];

        $updateData = [
            'name' => 'Updated Name',
            'settings' => [
                'currency' => 'USD',
                'language' => 'en',
                'theme' => 'dark'
            ]
        ];

        $result = $this->user->updateProfile($userId, $updateData);
        $this->assertTrue($result);

        $updatedUser = $this->user->getById($userId);
        $this->assertEquals($updateData['name'], $updatedUser['name']);
        $this->assertEquals($updateData['settings'], $updatedUser['settings']);
    }

    public function testVerifyEmail(): void
    {
        $userData = $this->createTestUser([
            'is_verified' => false,
            'verification_token' => 'test-token'
        ]);

        $result = $this->user->verifyEmail('test-token');
        $this->assertTrue($result);

        $verifiedUser = $this->user->getById($userData['_id']);
        $this->assertTrue($verifiedUser['is_verified']);
        $this->assertNull($verifiedUser['verification_token']);
    }

    public function testResetPassword(): void
    {
        $userData = $this->createTestUser();
        
        // Request password reset
        $this->user->resetPassword($userData['email']);
        
        $user = $this->user->getById($userData['_id']);
        $this->assertNotNull($user['reset_token']);
        $this->assertInstanceOf(\MongoDB\BSON\UTCDateTime::class, $user['reset_token_expires']);

        // Update password
        $newPassword = 'NewPassword123!';
        $result = $this->user->updatePassword(
            $userData['_id'],
            'password123', // Current password from createTestUser
            $newPassword
        );
        $this->assertTrue($result);

        // Verify new password works
        $authenticatedUser = $this->user->authenticate($userData['email'], $newPassword);
        $this->assertEquals($userData['_id'], $authenticatedUser['_id']);
    }

    public function testLoginAttempts(): void
    {
        $userData = $this->createTestUser();

        // Make multiple failed login attempts
        for ($i = 0; $i < MAX_LOGIN_ATTEMPTS; $i++) {
            try {
                $this->user->authenticate($userData['email'], 'wrongpassword');
            } catch (Exception $e) {
                continue;
            }
        }

        // Next attempt should throw account locked exception
        try {
            $this->user->authenticate($userData['email'], 'wrongpassword');
            $this->fail('Expected account locked exception not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Account locked due to too many failed attempts. Please try again later.', $e->getMessage());
        }

        // Successful login should reset attempts
        $this->user->authenticate($userData['email'], 'password123');
        $updatedUser = $this->user->getById($userData['_id']);
        $this->assertEquals(0, $updatedUser['login_attempts']);
    }

    public function testUpdateSettings(): void
    {
        $userData = $this->createTestUser();
        $userId = $userData['_id'];

        $settings = [
            'currency' => 'EUR',
            'language' => 'fr',
            'theme' => 'dark',
            'notifications_enabled' => false
        ];

        $result = $this->user->updateProfile($userId, ['settings' => $settings]);
        $this->assertTrue($result);

        $updatedUser = $this->user->getById($userId);
        $this->assertEquals($settings, $updatedUser['settings']);
    }

    public function testDeactivateAccount(): void
    {
        $userData = $this->createTestUser();
        $userId = $userData['_id'];

        $result = $this->user->updateProfile($userId, ['is_active' => false]);
        $this->assertTrue($result);

        // Try to authenticate with deactivated account
        try {
            $this->user->authenticate($userData['email'], 'password123');
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Your account has been deactivated', $e->getMessage());
        }
    }
}
