<?php
namespace Tests;

use App\Budget;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Exception;

class BudgetTest extends TestCase
{
    private $budget;
    private $userId;
    private $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->budget = new Budget($this->db);
        
        // Create test user
        $userData = $this->createTestUser();
        $this->userId = $userData['_id'];
        
        // Create test category
        $categoryData = $this->createTestCategory(['user_id' => $this->userId]);
        $this->categoryId = $categoryData['_id'];
    }

    public function testCreateBudget(): void
    {
        $budgetData = [
            'user_id' => $this->userId,
            'name' => 'Monthly Budget',
            'description' => 'Test budget',
            'amount' => 5000000,
            'period' => 'monthly',
            'category_ids' => [$this->categoryId],
            'start_date' => date('Y-m-01'),
            'color' => '#007bff',
            'icon' => 'fas fa-chart-pie',
            'notifications_enabled' => true,
            'alert_threshold' => 80
        ];

        $budgetId = $this->budget->create($budgetData);
        $this->assertInstanceOf(ObjectId::class, $budgetId);

        $createdBudget = $this->db->budgets->findOne(['_id' => $budgetId]);
        $this->assertNotNull($createdBudget);
        $this->assertEquals($budgetData['name'], $createdBudget['name']);
        $this->assertEquals($budgetData['amount'], $createdBudget['amount']);
        $this->assertEquals($budgetData['period'], $createdBudget['period']);
        $this->assertEquals($budgetData['category_ids'], array_map(function($id) {
            return (string)$id;
        }, $createdBudget['category_ids']));
        $this->assertTrue($createdBudget['is_active']);
    }

    public function testUpdateBudget(): void
    {
        $budgetData = $this->createTestBudget([
            'user_id' => $this->userId,
            'amount' => 5000000
        ]);

        $updateData = [
            'name' => 'Updated Budget',
            'description' => 'Updated description',
            'amount' => 6000000,
            'period' => 'quarterly',
            'color' => '#28a745',
            'icon' => 'fas fa-money-bill',
            'alert_threshold' => 90
        ];

        $result = $this->budget->update($budgetData['_id'], $updateData);
        $this->assertTrue($result);

        $updatedBudget = $this->budget->getById($budgetData['_id']);
        $this->assertEquals($updateData['name'], $updatedBudget['name']);
        $this->assertEquals($updateData['description'], $updatedBudget['description']);
        $this->assertEquals($updateData['amount'], $updatedBudget['amount']);
        $this->assertEquals($updateData['period'], $updatedBudget['period']);
        $this->assertEquals($updateData['color'], $updatedBudget['color']);
        $this->assertEquals($updateData['icon'], $updatedBudget['icon']);
        $this->assertEquals($updateData['alert_threshold'], $updatedBudget['alert_threshold']);
    }

    public function testDeleteBudget(): void
    {
        $budgetData = $this->createTestBudget([
            'user_id' => $this->userId
        ]);

        $result = $this->budget->delete($budgetData['_id']);
        $this->assertTrue($result);

        $deletedBudget = $this->db->budgets->findOne(['_id' => $budgetData['_id']]);
        $this->assertNull($deletedBudget);
    }

    public function testGetUserBudgets(): void
    {
        // Create multiple budgets
        $budgets = [
            ['name' => 'Monthly Budget', 'period' => 'monthly', 'is_active' => true],
            ['name' => 'Quarterly Budget', 'period' => 'quarterly', 'is_active' => true],
            ['name' => 'Old Budget', 'period' => 'monthly', 'is_active' => false]
        ];

        foreach ($budgets as $budget) {
            $this->createTestBudget(array_merge($budget, [
                'user_id' => $this->userId
            ]));
        }

        // Test getting active budgets
        $result = $this->budget->getUserBudgets($this->userId);
        $this->assertCount(2, $result);

        // Test getting all budgets including inactive
        $result = $this->budget->getUserBudgets($this->userId, true);
        $this->assertCount(3, $result);
    }

    public function testGetBudgetProgress(): void
    {
        $budgetData = $this->createTestBudget([
            'user_id' => $this->userId,
            'amount' => 5000000,
            'period' => 'monthly',
            'category_ids' => [$this->categoryId]
        ]);

        // Create transactions within budget period
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'amount' => 2000000,
            'type' => 'expense',
            'date' => new UTCDateTime()
        ]);

        $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'amount' => 1000000,
            'type' => 'expense',
            'date' => new UTCDateTime()
        ]);

        // Create transaction outside budget period (last month)
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'amount' => 1000000,
            'type' => 'expense',
            'date' => new UTCDateTime(strtotime('-1 month') * 1000)
        ]);

        $progress = $this->budget->getBudgetProgress($budgetData['_id']);
        
        $this->assertEquals(5000000, $progress['budget']);
        $this->assertEquals(3000000, $progress['spent']);
        $this->assertEquals(2000000, $progress['remaining']);
        $this->assertEquals(60, $progress['percentage']);
    }

    public function testCheckBudgetAlerts(): void
    {
        // Create budget with 80% alert threshold
        $budgetData = $this->createTestBudget([
            'user_id' => $this->userId,
            'amount' => 5000000,
            'alert_threshold' => 80,
            'notifications_enabled' => true,
            'category_ids' => [$this->categoryId]
        ]);

        // Create transactions totaling 90% of budget
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $this->categoryId,
            'amount' => 4500000,
            'type' => 'expense',
            'date' => new UTCDateTime()
        ]);

        $alerts = $this->budget->checkBudgetAlerts($this->userId);
        
        $this->assertCount(1, $alerts);
        $this->assertEquals($budgetData['_id'], $alerts[0]['budget_id']);
        $this->assertEquals(90, round($alerts[0]['percentage']));
        $this->assertEquals(80, $alerts[0]['threshold']);
        $this->assertEquals(500000, $alerts[0]['remaining']);
    }

    public function testCalculateDateRange(): void
    {
        $testDate = '2023-07-15';

        // Test monthly period
        $budgetData = $this->createTestBudget([
            'user_id' => $this->userId,
            'period' => 'monthly'
        ]);

        $progress = $this->budget->getBudgetProgress($budgetData['_id'], $testDate);
        $this->assertEquals('2023-07-01', $progress['period']['start']->toDateTime()->format('Y-m-d'));
        $this->assertEquals('2023-07-31', $progress['period']['end']->toDateTime()->format('Y-m-d'));

        // Test quarterly period
        $budgetData = $this->createTestBudget([
            'user_id' => $this->userId,
            'period' => 'quarterly'
        ]);

        $progress = $this->budget->getBudgetProgress($budgetData['_id'], $testDate);
        $this->assertEquals('2023-07-01', $progress['period']['start']->toDateTime()->format('Y-m-d'));
        $this->assertEquals('2023-09-30', $progress['period']['end']->toDateTime()->format('Y-m-d'));

        // Test yearly period
        $budgetData = $this->createTestBudget([
            'user_id' => $this->userId,
            'period' => 'yearly'
        ]);

        $progress = $this->budget->getBudgetProgress($budgetData['_id'], $testDate);
        $this->assertEquals('2023-01-01', $progress['period']['start']->toDateTime()->format('Y-m-d'));
        $this->assertEquals('2023-12-31', $progress['period']['end']->toDateTime()->format('Y-m-d'));
    }

    private function createTestBudget(array $attributes = []): array
    {
        $defaultAttributes = [
            'name' => 'Test Budget',
            'amount' => 5000000,
            'period' => 'monthly',
            'user_id' => $this->userId,
            'category_ids' => [$this->categoryId],
            'notifications_enabled' => true,
            'alert_threshold' => 80,
            'is_active' => true,
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime()
        ];

        $budgetData = array_merge($defaultAttributes, $attributes);
        $result = $this->db->budgets->insertOne($budgetData);
        $budgetData['_id'] = $result->getInsertedId();

        return $budgetData;
    }
}
