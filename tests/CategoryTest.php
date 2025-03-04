<?php
namespace Tests;

use App\Category;
use MongoDB\BSON\ObjectId;
use Exception;

class CategoryTest extends TestCase
{
    private $category;
    private $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = new Category($this->db);
        
        // Create test user
        $userData = $this->createTestUser();
        $this->userId = $userData['_id'];
    }

    public function testCreateCategory(): void
    {
        $categoryData = [
            'name' => 'Food & Dining',
            'type' => 'expense',
            'user_id' => $this->userId,
            'description' => 'Food and dining expenses',
            'color' => '#FF0000',
            'icon' => 'fas fa-utensils',
            'budget_limit' => 2000000
        ];

        $categoryId = $this->category->create($categoryData);
        $this->assertInstanceOf(ObjectId::class, $categoryId);

        $createdCategory = $this->db->categories->findOne(['_id' => $categoryId]);
        $this->assertNotNull($createdCategory);
        $this->assertEquals($categoryData['name'], $createdCategory['name']);
        $this->assertEquals($categoryData['type'], $createdCategory['type']);
        $this->assertEquals($categoryData['description'], $createdCategory['description']);
        $this->assertEquals($categoryData['color'], $createdCategory['color']);
        $this->assertEquals($categoryData['icon'], $createdCategory['icon']);
        $this->assertEquals($categoryData['budget_limit'], $createdCategory['budget_limit']);
        $this->assertFalse($createdCategory['is_default']);
    }

    public function testCreateDuplicateCategory(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Category already exists');

        $categoryData = [
            'name' => 'Test Category',
            'type' => 'expense',
            'user_id' => $this->userId
        ];

        // Create first category
        $this->category->create($categoryData);

        // Try to create duplicate category
        $this->category->create($categoryData);
    }

    public function testUpdateCategory(): void
    {
        $categoryData = $this->createTestCategory([
            'user_id' => $this->userId
        ]);

        $updateData = [
            'name' => 'Updated Category',
            'description' => 'Updated description',
            'color' => '#00FF00',
            'icon' => 'fas fa-edit',
            'budget_limit' => 3000000
        ];

        $result = $this->category->update($categoryData['_id'], $updateData);
        $this->assertTrue($result);

        $updatedCategory = $this->category->getById($categoryData['_id']);
        $this->assertEquals($updateData['name'], $updatedCategory['name']);
        $this->assertEquals($updateData['description'], $updatedCategory['description']);
        $this->assertEquals($updateData['color'], $updatedCategory['color']);
        $this->assertEquals($updateData['icon'], $updatedCategory['icon']);
        $this->assertEquals($updateData['budget_limit'], $updatedCategory['budget_limit']);
    }

    public function testDeleteCategory(): void
    {
        $categoryData = $this->createTestCategory([
            'user_id' => $this->userId
        ]);

        // Create a transaction using this category
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $categoryData['_id']
        ]);

        // Try to delete category with existing transactions
        try {
            $this->category->delete($categoryData['_id']);
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Cannot delete category that is in use', $e->getMessage());
        }

        // Delete all transactions for this category
        $this->db->transactions->deleteMany(['category_id' => $categoryData['_id']]);

        // Now delete should succeed
        $result = $this->category->delete($categoryData['_id']);
        $this->assertTrue($result);

        $deletedCategory = $this->db->categories->findOne(['_id' => $categoryData['_id']]);
        $this->assertNull($deletedCategory);
    }

    public function testGetUserCategories(): void
    {
        // Create multiple categories
        $categories = [
            ['name' => 'Food', 'type' => 'expense'],
            ['name' => 'Transport', 'type' => 'expense'],
            ['name' => 'Salary', 'type' => 'income']
        ];

        foreach ($categories as $category) {
            $this->createTestCategory(array_merge($category, [
                'user_id' => $this->userId
            ]));
        }

        // Test getting all categories
        $result = $this->category->getUserCategories($this->userId);
        $this->assertCount(3, $result);

        // Test getting expense categories
        $result = $this->category->getUserCategories($this->userId, 'expense');
        $this->assertCount(2, $result);

        // Test getting income categories
        $result = $this->category->getUserCategories($this->userId, 'income');
        $this->assertCount(1, $result);
    }

    public function testCreateDefaultCategories(): void
    {
        $result = $this->category->createDefaultCategories($this->userId);
        $this->assertTrue($result);

        $categories = $this->db->categories->find([
            'user_id' => $this->userId,
            'is_default' => true
        ])->toArray();

        // Check if all default categories were created
        $this->assertGreaterThan(0, count($categories));
        
        foreach ($categories as $category) {
            $this->assertTrue($category['is_default']);
            $this->assertNotEmpty($category['icon']);
            $this->assertNotEmpty($category['color']);
        }

        // Verify we have both income and expense categories
        $types = array_unique(array_column($categories, 'type'));
        sort($types);
        $this->assertEquals(['expense', 'income'], $types);
    }

    public function testGetCategoryStats(): void
    {
        $categoryData = $this->createTestCategory([
            'user_id' => $this->userId,
            'type' => 'expense'
        ]);

        // Create transactions for this category
        $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $categoryData['_id'],
            'amount' => 100000,
            'type' => 'expense'
        ]);

        $this->createTestTransaction([
            'user_id' => $this->userId,
            'category_id' => $categoryData['_id'],
            'amount' => 200000,
            'type' => 'expense'
        ]);

        $stats = $this->category->getCategoryStats(
            $this->userId,
            date('Y-m-01'),
            date('Y-m-t')
        );

        $this->assertCount(1, $stats);
        $categoryStats = $stats[0];
        $this->assertEquals($categoryData['_id'], $categoryStats['_id']['category_id']);
        $this->assertEquals('expense', $categoryStats['_id']['type']);
        $this->assertEquals(300000, $categoryStats['total']);
        $this->assertEquals(2, $categoryStats['count']);
    }
}
