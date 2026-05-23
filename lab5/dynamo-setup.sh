#!/bin/bash
# dynamo-setup.sh — Create DynamoDB ActivityLog table and add sample items

# Create table
aws dynamodb create-table \
    --table-name ActivityLog \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST \
    --region eu-central-1

echo "Waiting for table to be created..."
aws dynamodb wait table-exists --table-name ActivityLog --region eu-central-1

# Add sample items
aws dynamodb put-item \
    --table-name ActivityLog \
    --item '{
        "id": {"S": "log_001"},
        "action": {"S": "CREATE_TODO"},
        "details": {"S": "Created todo: Set up AWS RDS instance"},
        "timestamp": {"S": "2024-01-01 10:00:00"},
        "user_ip": {"S": "10.21.1.100"}
    }' \
    --region eu-central-1

aws dynamodb put-item \
    --table-name ActivityLog \
    --item '{
        "id": {"S": "log_002"},
        "action": {"S": "UPDATE_TODO"},
        "details": {"S": "Updated todo #1 status to done"},
        "timestamp": {"S": "2024-01-01 10:05:00"},
        "user_ip": {"S": "10.21.1.100"}
    }' \
    --region eu-central-1

aws dynamodb put-item \
    --table-name ActivityLog \
    --item '{
        "id": {"S": "log_003"},
        "action": {"S": "CREATE_CATEGORY"},
        "details": {"S": "Created category: Work"},
        "timestamp": {"S": "2024-01-01 09:55:00"},
        "user_ip": {"S": "10.21.1.100"}
    }' \
    --region eu-central-1

echo "DynamoDB table created and seeded successfully!"

# Scan to verify
aws dynamodb scan --table-name ActivityLog --region eu-central-1
