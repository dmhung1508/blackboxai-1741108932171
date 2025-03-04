<?php
namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use MongoDB\Client;

abstract class TestCase extends BaseTestCase
{
    protected $db;
    protected $testDb = 'financial_manager_test';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Connect to MongoDB
        $client = new Client("mongodb://localhost:27017");
        $this->db = $client->selectDatabase($this->testDb);
        
        // Clear test database
        $this->clearDatabase();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $this->clearDatabase();
        parent::tearDown();
    }

    protected function clearDatabase(): void
    {
        $collections = $this->db->listCollections();
        foreach ($collections as $collection) {
            $this->db->dropCollection($collection->getName());
        }
    }

    protected function createTestUser(array $attributes = []): array
    {
        $defaultAttributes = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'is_verified' => true,
            'is_active' => true,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        $userData = array_merge($defaultAttributes, $attributes);
        $result = $this->db->users->insertOne($userData);
        $userData['_id'] = $result->getInsertedId();

        return $userData;
    }

    protected function createTestCategory(array $attributes = []): array
    {
        $defaultAttributes = [
            'name' => 'Test Category',
            'type' => 'expense',
            'user_id' => new \MongoDB\BSON\ObjectId(),
            'color' => '#000000',
            'icon' => 'fas fa-folder',
            'is_default' => false,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        $categoryData = array_merge($defaultAttributes, $attributes);
        $result = $this->db->categories->insertOne($categoryData);
        $categoryData['_id'] = $result->getInsertedId();

        return $categoryData;
    }

    protected function createTestWallet(array $attributes = []): array
    {
        $defaultAttributes = [
            'name' => 'Test Wallet',
            'user_id' => new \MongoDB\BSON\ObjectId(),
            'balance' => 0.0,
            'type' => 'cash',
            'currency' => 'VND',
            'is_active' => true,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        $walletData = array_merge($defaultAttributes, $attributes);
        $result = $this->db->wallets->insertOne($walletData);
        $walletData['_id'] = $result->getInsertedId();

        return $walletData;
    }

    protected function createTestTransaction(array $attributes = []): array
    {
        $defaultAttributes = [
            'user_id' => new \MongoDB\BSON\ObjectId(),
            'amount' => 100000.0,
            'type' => 'expense',
            'category_id' => new \MongoDB\BSON\ObjectId(),
            'description' => 'Test Transaction',
            'date' => new \MongoDB\BSON\UTCDateTime(),
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        $transactionData = array_merge($defaultAttributes, $attributes);
        $result = $this->db->transactions->insertOne($transactionData);
        $transactionData['_id'] = $result->getInsertedId();

        return $transactionData;
    }

    protected function assertArrayHasKeys(array $keys, array $array): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }

    protected function assertModelEquals(array $expected, array $actual, array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($expected[$key])) {
                $this->assertEquals($expected[$key], $actual[$key]);
            }
        }
    }

    protected function assertDateEquals(\MongoDB\BSON\UTCDateTime $expected, \MongoDB\BSON\UTCDateTime $actual): void
    {
        $this->assertEquals(
            $expected->toDateTime()->format('Y-m-d H:i:s'),
            $actual->toDateTime()->format('Y-m-d H:i:s')
        );
    }
}
