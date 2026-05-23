-- setup.sql — Run this on RDS Master after connecting
-- mysql -h <RDS_ENDPOINT> -u admin -p project_db < setup.sql

USE project_db;

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Todos table (1-to-many with categories)
CREATE TABLE IF NOT EXISTS todos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category_id INT NOT NULL,
    status ENUM('pending', 'in_progress', 'done') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Seed data
INSERT INTO categories (name) VALUES
    ('Work'),
    ('Personal'),
    ('Shopping');

INSERT INTO todos (title, category_id, status) VALUES
    ('Set up AWS RDS instance', 1, 'done'),
    ('Configure Read Replica', 1, 'in_progress'),
    ('Write lab report', 1, 'pending'),
    ('Buy groceries', 3, 'pending'),
    ('Call dentist', 2, 'pending'),
    ('Deploy application to EC2', 1, 'in_progress');

-- Verify
SELECT t.id, t.title, c.name AS category, t.status
FROM todos t
JOIN categories c ON t.category_id = c.id
ORDER BY t.id;
