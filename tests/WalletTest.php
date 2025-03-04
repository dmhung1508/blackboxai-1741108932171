<?php
namespace Tests;

use App\Wallet;
use MongoDB\BSON\ObjectId;
use Exception;

class WalletTest extends TestCase
{
    private $wallet;
    private $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = new Wallet($this->db);
        
        // Create test user
        $userData = $this->createTestUser();
        $this->userId = $userData['_id'];
    }

    public function testCreateWallet(): void
    {
        $walletData = [
            'name' => 'Cash Wallet',
            'user_id' => $this->userId,
            'description' => 'My cash wallet',
            'type' => 'cash',
            'currency' => 'VND',
            'initial_balance' => 1000000,
            'icon' => 'fas fa-wallet',
            'color' => '#28a745'
        ];

        $walletId = $this->wallet->create($walletData);
        $this->assertInstanceOf(ObjectId::class, $walletId);

        $createdWallet = $this->db->wallets->findOne(['_id' => $walletId]);
        $this->assertNotNull($createdWallet);
        $this->assertEquals($walletData['name'], $createdWallet['name']);
        $this->assertEquals($walletData['type'], $createdWallet['type']);
        $this->assertEquals($walletData['currency'], $createdWallet['currency']);
        $this->assertEquals($walletData['initial_balance'], $createdWallet['balance']);
        $this->assertEquals($walletData['icon'], $createdWallet['icon']);
        $this->assertEquals($walletData['color'], $createdWallet['color']);
        $this->assertTrue($createdWallet['is_active']);
    }

    public function testCreateDuplicateWallet(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wallet name already exists');

        $walletData = [
            'name' => 'Test Wallet',
            'user_id' => $this->userId,
            'initial_balance' => 0
        ];

        // Create first wallet
        $this->wallet->create($walletData);

        // Try to create duplicate wallet
        $this->wallet->create($walletData);
    }

    public function testUpdateWallet(): void
    {
        $walletData = $this->createTestWallet([
            'user_id' => $this->userId,
            'balance' => 1000000
        ]);

        $updateData = [
            'name' => 'Updated Wallet',
            'description' => 'Updated description',
            'type' => 'bank',
            'currency' => 'USD',
            'icon' => 'fas fa-university',
            'color' => '#007bff'
        ];

        $result = $this->wallet->update($walletData['_id'], $updateData);
        $this->assertTrue($result);

        $updatedWallet = $this->wallet->getById($walletData['_id']);
        $this->assertEquals($updateData['name'], $updatedWallet['name']);
        $this->assertEquals($updateData['description'], $updatedWallet['description']);
        $this->assertEquals($updateData['type'], $updatedWallet['type']);
        $this->assertEquals($updateData['currency'], $updatedWallet['currency']);
        $this->assertEquals($updateData['icon'], $updatedWallet['icon']);
        $this->assertEquals($updateData['color'], $updatedWallet['color']);
        // Balance should remain unchanged
        $this->assertEquals($walletData['balance'], $updatedWallet['balance']);
    }

    public function testDeleteWallet(): void
    {
        $walletData = $this->createTestWallet([
            'user_id' => $this->userId
        ]);

        // Create a transaction using this wallet
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'wallet_id' => $walletData['_id']
        ]);

        // Try to delete wallet with existing transactions
        try {
            $this->wallet->delete($walletData['_id']);
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Cannot delete wallet with existing transactions', $e->getMessage());
        }

        // Delete all transactions for this wallet
        $this->db->transactions->deleteMany(['wallet_id' => $walletData['_id']]);

        // Now delete should succeed
        $result = $this->wallet->delete($walletData['_id']);
        $this->assertTrue($result);

        $deletedWallet = $this->db->wallets->findOne(['_id' => $walletData['_id']]);
        $this->assertNull($deletedWallet);
    }

    public function testGetUserWallets(): void
    {
        // Create multiple wallets
        $wallets = [
            ['name' => 'Cash', 'type' => 'cash', 'is_active' => true],
            ['name' => 'Bank', 'type' => 'bank', 'is_active' => true],
            ['name' => 'Old Wallet', 'type' => 'cash', 'is_active' => false]
        ];

        foreach ($wallets as $wallet) {
            $this->createTestWallet(array_merge($wallet, [
                'user_id' => $this->userId
            ]));
        }

        // Test getting active wallets
        $result = $this->wallet->getUserWallets($this->userId);
        $this->assertCount(2, $result);

        // Test getting all wallets including inactive
        $result = $this->wallet->getUserWallets($this->userId, true);
        $this->assertCount(3, $result);
    }

    public function testAdjustBalance(): void
    {
        $walletData = $this->createTestWallet([
            'user_id' => $this->userId,
            'balance' => 1000000
        ]);

        // Test increment
        $result = $this->wallet->adjustBalance($walletData['_id'], 500000, 'increment');
        $this->assertTrue($result);

        $wallet = $this->wallet->getById($walletData['_id']);
        $this->assertEquals(1500000, $wallet['balance']);

        // Test decrement
        $result = $this->wallet->adjustBalance($walletData['_id'], 300000, 'decrement');
        $this->assertTrue($result);

        $wallet = $this->wallet->getById($walletData['_id']);
        $this->assertEquals(1200000, $wallet['balance']);
    }

    public function testGetTotalBalance(): void
    {
        // Create wallets with different currencies
        $wallets = [
            ['name' => 'VND Cash', 'currency' => 'VND', 'balance' => 1000000],
            ['name' => 'VND Bank', 'currency' => 'VND', 'balance' => 2000000],
            ['name' => 'USD Account', 'currency' => 'USD', 'balance' => 100],
            ['name' => 'Hidden Wallet', 'currency' => 'VND', 'balance' => 500000, 'exclude_from_stats' => true]
        ];

        foreach ($wallets as $wallet) {
            $this->createTestWallet(array_merge($wallet, [
                'user_id' => $this->userId,
                'is_active' => true
            ]));
        }

        // Test total balance for VND
        $result = $this->wallet->getTotalBalance($this->userId, 'VND');
        $this->assertCount(1, $result);
        $this->assertEquals(3000000, $result[0]['total']); // Excludes hidden wallet

        // Test total balance for USD
        $result = $this->wallet->getTotalBalance($this->userId, 'USD');
        $this->assertCount(1, $result);
        $this->assertEquals(100, $result[0]['total']);

        // Test total balance for all currencies
        $result = $this->wallet->getTotalBalance($this->userId);
        $this->assertCount(2, $result);
    }

    public function testTransfer(): void
    {
        // Create source and destination wallets
        $sourceWallet = $this->createTestWallet([
            'user_id' => $this->userId,
            'balance' => 1000000,
            'currency' => 'VND'
        ]);

        $destWallet = $this->createTestWallet([
            'user_id' => $this->userId,
            'balance' => 500000,
            'currency' => 'VND'
        ]);

        // Test successful transfer
        $result = $this->wallet->transfer(
            $sourceWallet['_id'],
            $destWallet['_id'],
            300000,
            'Test transfer'
        );
        $this->assertTrue($result);

        // Verify wallet balances
        $updatedSource = $this->wallet->getById($sourceWallet['_id']);
        $this->assertEquals(700000, $updatedSource['balance']);

        $updatedDest = $this->wallet->getById($destWallet['_id']);
        $this->assertEquals(800000, $updatedDest['balance']);

        // Verify transfer transactions were created
        $transactions = $this->db->transactions->find([
            'description' => ['$regex' => 'Test transfer']
        ])->toArray();
        $this->assertCount(2, $transactions);

        // Test insufficient funds
        try {
            $this->wallet->transfer(
                $sourceWallet['_id'],
                $destWallet['_id'],
                1000000,
                'Should fail'
            );
            $this->fail('Expected insufficient funds exception');
        } catch (Exception $e) {
            $this->assertEquals('Insufficient funds', $e->getMessage());
        }
    }
}
