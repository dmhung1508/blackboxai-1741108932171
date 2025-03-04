<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getDatabase();

    echo "Starting database initialization...\n";

    // Create collections with validators
    echo "Creating collections and setting up validation rules...\n";

    // Users Collection
    $db->createCollection('users', [
        'validator' => [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['email', 'password', 'name', 'created_at'],
                'properties' => [
                    'email' => ['bsonType' => 'string'],
                    'password' => ['bsonType' => 'string'],
                    'name' => ['bsonType' => 'string'],
                    'is_verified' => ['bsonType' => 'bool'],
                    'verification_token' => ['bsonType' => ['string', 'null']],
                    'reset_token' => ['bsonType' => ['string', 'null']],
                    'reset_token_expires' => ['bsonType' => ['date', 'null']],
                    'last_login' => ['bsonType' => ['date', 'null']],
                    'login_attempts' => ['bsonType' => 'int'],
                    'is_active' => ['bsonType' => 'bool'],
                    'created_at' => ['bsonType' => 'date'],
                    'updated_at' => ['bsonType' => 'date']
                ]
            ]
        ]
    ]);

    // Transactions Collection
    $db->createCollection('transactions', [
        'validator' => [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['user_id', 'amount', 'type', 'date', 'created_at'],
                'properties' => [
                    'user_id' => ['bsonType' => 'objectId'],
                    'amount' => ['bsonType' => 'double'],
                    'type' => ['enum' => ['income', 'expense']],
                    'category_id' => ['bsonType' => 'objectId'],
                    'wallet_id' => ['bsonType' => ['objectId', 'null']],
                    'description' => ['bsonType' => 'string'],
                    'date' => ['bsonType' => 'date'],
                    'tags' => ['bsonType' => 'array'],
                    'created_at' => ['bsonType' => 'date'],
                    'updated_at' => ['bsonType' => 'date']
                ]
            ]
        ]
    ]);

    // Categories Collection
    $db->createCollection('categories', [
        'validator' => [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['user_id', 'name', 'type', 'created_at'],
                'properties' => [
                    'user_id' => ['bsonType' => 'objectId'],
                    'name' => ['bsonType' => 'string'],
                    'type' => ['enum' => ['income', 'expense']],
                    'description' => ['bsonType' => 'string'],
                    'color' => ['bsonType' => 'string'],
                    'icon' => ['bsonType' => 'string'],
                    'is_default' => ['bsonType' => 'bool'],
                    'budget_limit' => ['bsonType' => 'double'],
                    'created_at' => ['bsonType' => 'date'],
                    'updated_at' => ['bsonType' => 'date']
                ]
            ]
        ]
    ]);

    // Wallets Collection
    $db->createCollection('wallets', [
        'validator' => [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['user_id', 'name', 'balance', 'created_at'],
                'properties' => [
                    'user_id' => ['bsonType' => 'objectId'],
                    'name' => ['bsonType' => 'string'],
                    'description' => ['bsonType' => 'string'],
                    'type' => ['enum' => ['cash', 'bank', 'credit_card', 'e-wallet']],
                    'currency' => ['bsonType' => 'string'],
                    'balance' => ['bsonType' => 'double'],
                    'icon' => ['bsonType' => 'string'],
                    'color' => ['bsonType' => 'string'],
                    'is_active' => ['bsonType' => 'bool'],
                    'exclude_from_stats' => ['bsonType' => 'bool'],
                    'created_at' => ['bsonType' => 'date'],
                    'updated_at' => ['bsonType' => 'date']
                ]
            ]
        ]
    ]);

    // Budgets Collection
    $db->createCollection('budgets', [
        'validator' => [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['user_id', 'name', 'amount', 'period', 'created_at'],
                'properties' => [
                    'user_id' => ['bsonType' => 'objectId'],
                    'name' => ['bsonType' => 'string'],
                    'description' => ['bsonType' => 'string'],
                    'amount' => ['bsonType' => 'double'],
                    'period' => ['enum' => ['monthly', 'quarterly', 'yearly']],
                    'category_ids' => ['bsonType' => 'array'],
                    'start_date' => ['bsonType' => 'date'],
                    'end_date' => ['bsonType' => ['date', 'null']],
                    'color' => ['bsonType' => 'string'],
                    'icon' => ['bsonType' => 'string'],
                    'notifications_enabled' => ['bsonType' => 'bool'],
                    'alert_threshold' => ['bsonType' => 'int'],
                    'is_active' => ['bsonType' => 'bool'],
                    'created_at' => ['bsonType' => 'date'],
                    'updated_at' => ['bsonType' => 'date']
                ]
            ]
        ]
    ]);

    // Activity Logs Collection
    $db->createCollection('activity_logs', [
        'validator' => [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['user_id', 'action', 'created_at'],
                'properties' => [
                    'user_id' => ['bsonType' => 'objectId'],
                    'action' => ['bsonType' => 'string'],
                    'details' => ['bsonType' => ['object', 'null']],
                    'ip_address' => ['bsonType' => 'string'],
                    'user_agent' => ['bsonType' => 'string'],
                    'created_at' => ['bsonType' => 'date']
                ]
            ]
        ]
    ]);

    echo "Creating indexes...\n";

    // Users Indexes
    $db->users->createIndex(['email' => 1], ['unique' => true]);
    $db->users->createIndex(['verification_token' => 1]);
    $db->users->createIndex(['reset_token' => 1]);

    // Transactions Indexes
    $db->transactions->createIndex(['user_id' => 1, 'date' => -1]);
    $db->transactions->createIndex(['category_id' => 1]);
    $db->transactions->createIndex(['wallet_id' => 1]);
    $db->transactions->createIndex(['type' => 1]);
    $db->transactions->createIndex(['tags' => 1]);

    // Categories Indexes
    $db->categories->createIndex(['user_id' => 1, 'type' => 1]);
    $db->categories->createIndex(['user_id' => 1, 'name' => 1], ['unique' => true]);

    // Wallets Indexes
    $db->wallets->createIndex(['user_id' => 1]);
    $db->wallets->createIndex(['user_id' => 1, 'name' => 1], ['unique' => true]);

    // Budgets Indexes
    $db->budgets->createIndex(['user_id' => 1]);
    $db->budgets->createIndex(['category_ids' => 1]);
    $db->budgets->createIndex(['period' => 1]);

    // Activity Logs Indexes
    $db->activity_logs->createIndex(['user_id' => 1, 'created_at' => -1]);
    $db->activity_logs->createIndex(['action' => 1]);
    $db->activity_logs->createIndex(['created_at' => 1], ['expireAfterSeconds' => 7776000]); // 90 days TTL

    echo "Database initialization completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
