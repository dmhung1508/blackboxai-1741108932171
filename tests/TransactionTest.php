<?php
namespace Tests;

use App\Transaction;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Exception;

class TransactionTest extends TestCase
{
    private $transaction;
    private $userId;
    private $categoryId;
    private $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transaction = new Transaction($this->db);
        
        // Create test user
        $userData = $this->createTestUser();
        $this->userId = $userData['_id'];
        
        // Create test category
        $categoryData = $this->createTestCategory(['user_id' => $this->userId]);
        $this->categoryId = $categoryData['_id'];
        
        // Create test wallet
        $walletData = $this->createTestWallet(['user_id' => $this->userId]);
        $this->walletId = $walletData['_id'];
    }

    public function testCreateTransaction(): void
    {
        $transactionData = [
            'user_id' => $this->userId,
            'amount' => 100000,
            'type' => 'expense',
            'category_id' => $this->categoryId,
            'wallet_id' => $this->walletId,
            'description' => 'Test transaction',
            'date' => new UTCDateTime(),
            'tags' => ['food', 'lunch']
        ];

        $transactionId = $this->transaction->create($transactionData);
        $this->assertInstanceOf(ObjectId::class, $transactionId);

        $createdTransaction = $this->db->transactions->findOne(['_id' => $transactionId]);
        $this->assertNotNull($createdTransaction);
        $this->assertEquals($transactionData['amount'], $createdTransaction['amount']);
        $this->assertEquals($transactionData['type'], $createdTransaction['type']);
        $this->assertEquals($transactionData['description'], $createdTransaction['description']);
        $this->assertEquals($transactionData['tags'], $createdTransaction['tags']);
    }

    public function testCreateTransactionUpdatesWalletBalance(): void
    {
        $initialBalance = 1000000;
        $this->db->wallets->updateOne(
            ['_id' => $this->walletId],
            ['$set' => ['balance' => $initialBalance]]
        );

        // Create expense transaction
        $expenseAmount = 100000;
        $this->transaction->create([
            'user_id' => $this->userId,
            'amount' => $expenseAmount,
            'type' => 'expense',
            'category_id' => $this->categoryId,
            'wallet_id' => $this->walletId,
            'description' => 'Test expense'
        ]);

        $wallet = $this->db->wallets->findOne(['_id' => $this->walletId]);
        $this->assertEquals($initialBalance - $expenseAmount, $wallet['balance']);

        // Create income transaction
        $incomeAmount = 200000;
        $this->transaction->create([
            'user_id' => $this->userId,
            'amount' => $incomeAmount,
            'type' => 'income',
            'category_id' => $this->categoryId,
            'wallet_id' => $this->walletId,
            'description' => 'Test income'
        ]);

        $wallet = $this->db->wallets->findOne(['_id' => $this->walletId]);
        $this->assertEquals($initialBalance - $expenseAmount + $incomeAmount, $wallet['balance']);
    }

    public function testUpdateTransaction(): void
    {
        $transactionData = $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'wallet_id' => $this->walletId
        ]);

        $updateData = [
            'amount' => 150000,
            'description' => 'Updated description',
            'tags' => ['updated', 'tags']
        ];

        $result = $this->transaction->update($transactionData['_id'], $updateData);
        $this->assertTrue($result);

        $updatedTransaction = $this->transaction->getById($transactionData['_id']);
        $this->assertEquals($updateData['amount'], $updatedTransaction['amount']);
        $this->assertEquals($updateData['description'], $updatedTransaction['description']);
        $this->assertEquals($updateData['tags'], $updatedTransaction['tags']);
    }

    public function testDeleteTransaction(): void
    {
        $transactionData = $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'wallet_id' => $this->walletId,
            'amount' => 100000,
            'type' => 'expense'
        ]);

        // Get initial wallet balance
        $initialWallet = $this->db->wallets->findOne(['_id' => $this->walletId]);
        $initialBalance = $initialWallet['balance'];

        // Delete transaction
        $result = $this->transaction->delete($transactionData['_id']);
        $this->assertTrue($result);

        // Verify transaction is deleted
        $deletedTransaction = $this->db->transactions->findOne(['_id' => $transactionData['_id']]);
        $this->assertNull($deletedTransaction);

        // Verify wallet balance is updated
        $updatedWallet = $this->db->wallets->findOne(['_id' => $this->walletId]);
        $this->assertEquals($initialBalance + $transactionData['amount'], $updatedWallet['balance']);
    }

    public function testGetUserTransactions(): void
    {
        // Create multiple transactions
        $transactions = [];
        for ($i = 0; $i < 5; $i++) {
            $transactions[] = $this->createTestTransaction([
                'user_id' => $this->userId,
                'category_id' => $this->categoryId,
                'amount' => ($i + 1) * 100000,
                'type' => $i % 2 === 0 ? 'expense' : 'income',
                'date' => new UTCDateTime(strtotime("-$i days") * 1000)
            ]);
        }

        // Test without filters
        $result = $this->transaction->getUserTransactions($this->userId);
        $this->assertCount(5, $result);

        // Test with type filter
        $result = $this->transaction->getUserTransactions($this->userId, ['type' => 'expense']);
        $this->assertCount(3, $result);

        // Test with date range filter
        $result = $this->transaction->getUserTransactions($this->userId, [
            'start_date' => date('Y-m-d', strtotime('-2 days')),
            'end_date' => date('Y-m-d')
        ]);
        $this->assertCount(3, $result);

        // Test with pagination
        $result = $this->transaction->getUserTransactions($this->userId, [
            'limit' => 2,
            'page' => 0
        ]);
        $this->assertCount(2, $result);
    }

    public function testGetMonthlyStats(): void
    {
        $month = date('m');
        $year = date('Y');

        // Create transactions for current month
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'amount' => 1000000,
            'type' => 'income',
            'date' => new UTCDateTime(strtotime("$year-$month-01") * 1000)
        ]);

        $this->createTestTransaction([
            'user_id' => $this->userId,
            'amount' => 500000,
            'type' => 'expense',
            'date' => new UTCDateTime(strtotime("$year-$month-15") * 1000)
        ]);

        // Create transaction for previous month (should not be included)
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'amount' => 200000,
            'type' => 'expense',
            'date' => new UTCDateTime(strtotime('first day of previous month') * 1000)
        ]);

        $stats = $this->transaction->getMonthlyStats($this->userId, $year, $month);
        
        $this->assertEquals(1000000, $stats['income']);
        $this->assertEquals(500000, $stats['expense']);
        $this->assertEquals(500000, $stats['balance']);
    }

    public function testGetCategoryStats(): void
    {
        // Create multiple categories
        $categories = [
            $this->createTestCategory(['user_id' => $this->userId, 'name' => 'Food']),
            $this->createTestCategory(['user_id' => $this->userId, 'name' => 'Transport'])
        ];

        // Create transactions for each category
        foreach ($categories as $index => $category) {
            $this->createTestTransaction([
                'user_id' => $this->userId,
                'category_id' => $category['_id'],
                'amount' => ($index + 1) * 100000,
                'type' => 'expense',
                'date' => new UTCDateTime()
            ]);
        }

        $stats = $this->transaction->getCategoryStats(
            $this->userId,
            date('Y-m-01'),
            date('Y-m-t')
        );

        $this->assertCount(2, $stats);
        $this->assertEquals(300000, array_sum(array_column($stats, 'total')));
    }
}
