# Лабораторная работа №5. Amazon RDS и DynamoDB

## Описание лабораторной работы

Лабораторная работа посвящена работе с управляемыми базами данных AWS. Создан экземпляр Amazon RDS (MySQL), настроена Read Replica, выполнены базовые CRUD-операции, развёрнуто веб-приложение Task Manager с разделением чтения и записи между мастером и репликой. В качестве дополнительного задания реализована интеграция с Amazon DynamoDB для логирования активности.

---

## Постановка задачи

- Создать VPC с публичными и приватными подсетями
- Настроить Security Groups для приложения и БД
- Развернуть Amazon RDS MySQL в приватной подсети
- Создать EC2-инстанс для подключения к БД
- Выполнить CRUD-операции через MySQL клиент
- Создать Read Replica и проверить репликацию
- Развернуть PHP CRUD-приложение с разделением master/replica
- Создать DynamoDB таблицу для логирования активности

---

## Цель и основные этапы работы

**Цель:** Освоить управляемые реляционные (RDS) и NoSQL (DynamoDB) базы данных AWS, научиться настраивать Read Replica и интегрировать БД с веб-приложением.

**Этапы:**
1. Подготовка VPC и Security Groups
2. Создание Subnet Group и RDS MySQL
3. Создание EC2-инстанса
4. Подключение к RDS и CRUD-операции
5. Создание Read Replica
6. Развёртывание CRUD-приложения
7. Настройка DynamoDB (доп. задание)

---

## Практическая часть

### Шаг 1. Подготовка среды

Использована VPC из лабораторной работы №3: `student-vpc-k21` (10.21.0.0/16) с публичной (`10.21.1.0/24`) и приватной (`10.21.2.0/24`) подсетями.

#### Security Group: `web-security-group`

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| HTTP | TCP | 80 | 0.0.0.0/0 |
| SSH | TCP | 22 | Мой IP |
<img width="951" height="686" alt="image" src="https://github.com/user-attachments/assets/35983efa-33ac-495b-b708-e9d1b57c0515" />


**Outbound rules:**

| Тип | Протокол | Порт | Назначение |
|---|---|---|---|
| MySQL/Aurora | TCP | 3306 | db-mysql-security-group |
<img width="955" height="652" alt="image" src="https://github.com/user-attachments/assets/a226faec-c402-4817-8d4a-76975f7632f9" />


#### Security Group: `db-mysql-security-group`

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| MySQL/Aurora | TCP | 3306 | web-security-group |

> <img width="955" height="652" alt="image" src="https://github.com/user-attachments/assets/f17d3a4e-73b7-4950-ba20-bde1df90fd4a" />



---

### Шаг 2. Развёртывание Amazon RDS

#### Что такое Subnet Group? Зачем он нужен для RDS?

**DB Subnet Group** — это набор подсетей в разных зонах доступности (AZ), в которых может быть развёрнут экземпляр RDS. AWS требует Subnet Group по нескольким причинам:
- RDS должен знать, в каких подсетях и AZ размещать БД и её резервные копии
- Для Multi-AZ deployments AWS автоматически создаёт standby-реплику в другой AZ из группы
- Subnet Group обеспечивает изоляцию: БД размещается в приватных подсетях, недоступных из интернета
- При создании Read Replica AWS выбирает подсеть из той же группы

#### Создание Subnet Group

**Параметры:**

| Параметр | Значение |
|---|---|
| Name | `project-rds-subnet-group` |
| VPC | `student-vpc-k21` |
| Subnets | `private-subnet-k21` (eu-central-1a) + вторая приватная (eu-central-1b) |

> <img width="1037" height="527" alt="image" src="https://github.com/user-attachments/assets/68810b02-154d-460c-8459-f8201b289995" />


#### Создание RDS MySQL

**Параметры:**

| Параметр | Значение |
|---|---|
| Engine | MySQL 8.0.42 |
| Template | Free tier |
| DB instance identifier | `project-rds-mysql-prod` |
| Master username | `admin` |
| Instance class | `db.t3.micro` |
| Storage | 20 GB gp3, autoscaling до 100 GB |
| VPC | `student-vpc-k21` |
| Subnet group | `project-rds-subnet-group` |
| Public access | No |
| Security group | `db-mysql-security-group` |
| Initial database name | `project_db` |
| Automated backups | Enabled |

Нажата кнопка **Create database**. Ожидание статуса **Available** (~10-15 минут).

><img width="1400" height="593" alt="image" src="https://github.com/user-attachments/assets/659937a1-47e6-44dc-b34c-7ff76a7b248a" />


---

### Шаг 3. Создание EC2-инстанса

| Параметр | Значение |
|---|---|
| Name | `web-server` |
| AMI | Amazon Linux 2023 |
| Instance type | `t3.micro` |
| VPC | `student-vpc-k21` |
| Subnet | `public-subnet-k21` |
| Auto-assign Public IP | Enable |
| Security Group | `web-security-group` |

**User Data:**
```bash
#!/bin/bash
dnf update -y
dnf install -y mariadb105
```

> <img width="441" height="798" alt="image" src="https://github.com/user-attachments/assets/a68439de-4020-4e38-ac71-ac38d84ed927" />



---

### Шаг 4. Подключение к RDS и CRUD-операции

```bash
# Подключение к EC2
ssh -i student-key-k21.pem ec2-user@<EC2-Public-IP>

# Подключение к RDS
mysql -h <RDS_ENDPOINT> -u admin -p
# Вводим пароль

# Выбор БД
USE project_db;
```

**Создание таблиц (связь 1-ко-многим):**

```sql
-- Таблица категорий
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица задач (category_id — внешний ключ)
CREATE TABLE todos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category_id INT NOT NULL,
    status ENUM('pending', 'in_progress', 'done') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);
```

**Вставка данных:**

```sql
INSERT INTO categories (name) VALUES ('Work'), ('Personal'), ('Shopping');

INSERT INTO todos (title, category_id, status) VALUES
    ('Set up AWS RDS instance', 1, 'done'),
    ('Configure Read Replica', 1, 'in_progress'),
    ('Write lab report', 1, 'pending'),
    ('Buy groceries', 3, 'pending'),
    ('Call dentist', 2, 'pending'),
    ('Deploy application to EC2', 1, 'in_progress');
```

**Запросы на выборку с JOIN:**

```sql
-- Все задачи с категориями
SELECT t.id, t.title, c.name AS category, t.status
FROM todos t
JOIN categories c ON t.category_id = c.id
ORDER BY t.id;

-- Задачи по категории Work
SELECT t.title, t.status
FROM todos t
JOIN categories c ON t.category_id = c.id
WHERE c.name = 'Work';

-- Количество задач по категориям
SELECT c.name, COUNT(t.id) AS task_count
FROM categories c
LEFT JOIN todos t ON c.id = t.category_id
GROUP BY c.id, c.name;
```

> <img width="776" height="534" alt="image" src="https://github.com/user-attachments/assets/add5d71d-a085-4d5e-9860-e63801730c85" />


---

### Шаг 5. Создание Read Replica

В консоли RDS выбран `project-rds-mysql-prod` → **Actions → Create read replica**.

**Параметры:**

| Параметр | Значение |
|---|---|
| DB instance identifier | `project-rds-mysql-read-replica` |
| Instance class | `db.t3.micro` |
| Storage type | gp3 |
| Public access | No |
| Security group | `db-mysql-security-group` |
| Enhanced monitoring | Disabled |



**Подключение к Read Replica и проверка:**

```bash
mysql -h <REPLICA_ENDPOINT> -u admin -p
USE project_db;

-- Чтение данных
SELECT t.id, t.title, c.name AS category, t.status
FROM todos t JOIN categories c ON t.category_id = c.id;
```

**Какие данные вы видите? Объясните почему.**

Видны все те же данные, что и на мастере — все 6 задач и 3 категории. Это происходит потому, что Read Replica является точной асинхронной копией мастера. AWS непрерывно реплицирует все изменения с мастера на реплику через бинарный лог (binlog). Данные синхронизируются практически в реальном времени (задержка обычно менее секунды).

**Попытка записи на реплику:**

```sql
INSERT INTO todos (title, category_id) VALUES ('Test write on replica', 1);
-- ERROR 1290 (HY000): The MySQL server is running with the
-- --read-only option so it cannot execute this statement
```

**Получилось ли выполнить запись на Read Replica? Почему?**

Нет. Read Replica работает в режиме `read-only` — этот параметр выставляется автоматически AWS RDS. Запись на реплику заблокирована, чтобы гарантировать целостность данных: все изменения должны идти через мастер, а реплика только получает их через репликацию.

**Проверка репликации новой записи:**

```sql
-- На мастере
INSERT INTO todos (title, category_id, status) VALUES ('New task after replica', 1, 'pending');

-- На реплике (через несколько секунд)
SELECT * FROM todos ORDER BY id DESC LIMIT 1;
```

**Отобразилась ли новая запись на реплике? Объясните почему.**

Да, новая запись появилась на реплике через несколько секунд. RDS использует асинхронную репликацию на основе бинарного лога MySQL: каждая транзакция на мастере записывается в binlog, откуда реплика читает и применяет изменения. Задержка минимальна при нормальной нагрузке.

**Зачем нужны Read Replicas и в каких сценариях они полезны?**

Read Replicas решают несколько задач:

1. **Масштабирование чтения** — если приложение делает много SELECT-запросов (например, аналитика, отчёты), нагрузку можно распределить на несколько реплик, не нагружая мастер
2. **Отказоустойчивость** — реплика может быть повышена до мастера (promote) при сбое основного экземпляра
3. **Географическое распределение** — реплику можно создать в другом регионе для снижения задержки для пользователей
4. **Тестирование и аналитика** — тяжёлые аналитические запросы можно выполнять на реплике, не влияя на production мастер
5. **Резервное копирование** — снимки бэкапов можно делать с реплики, не замедляя мастер

> <img width="1273" height="910" alt="image" src="https://github.com/user-attachments/assets/2d644d95-c1c2-428e-923b-0800e968b211" />


---

### Шаг 6a. Развёртывание CRUD-приложения

Разработано веб-приложение **Task Manager** на PHP. Архитектура:
- **Запись (INSERT/UPDATE/DELETE)** → RDS Master
- **Чтение (SELECT)** → RDS Read Replica
- **Логирование действий** → DynamoDB

**Установка зависимостей на EC2:**

```bash
sudo dnf install -y nginx php php-fpm php-pdo php-mysqlnd php-json
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer require aws/aws-sdk-php
```

**Настройка переменных окружения:**

```bash
export DB_MASTER_HOST="project-rds-mysql-prod.xxxxx.eu-central-1.rds.amazonaws.com"
export DB_REPLICA_HOST="project-rds-mysql-read-replica.xxxxx.eu-central-1.rds.amazonaws.com"
export DB_PASS="YourPassword123!"
```

**Деплой:**

```bash
sudo cp -r app/* /var/www/html/
sudo systemctl restart nginx php-fpm
```



---

### Шаг 7. Дополнительное задание — Amazon DynamoDB

#### Проектирование таблицы

Создана таблица **ActivityLog** для хранения логов всех действий пользователя в приложении.

**Выбор ключей:**

| Параметр | Значение | Обоснование |
|---|---|---|
| Table name | `ActivityLog` | Логи активности пользователей |
| Primary Key (Hash) | `id` (String) | Уникальный идентификатор каждой записи лога (uniqid) |

Sort Key не использован, т.к. каждая запись лога уникальна и поиск происходит по scan с фильтрацией по timestamp.

**Атрибуты записи:**

| Атрибут | Тип | Описание |
|---|---|---|
| `id` | String | Уникальный ID (uniqid) |
| `action` | String | Тип действия (CREATE_TODO, UPDATE_TODO, ...) |
| `details` | String | Описание действия |
| `timestamp` | String | Дата и время |
| `user_ip` | String | IP пользователя |

**Создание таблицы через CLI:**

```bash
aws dynamodb create-table \
    --table-name ActivityLog \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST \
    --region eu-central-1
```



**Добавление тестовых записей:**

```bash
aws dynamodb put-item \
    --table-name ActivityLog \
    --item '{
        "id": {"S": "log_001"},
        "action": {"S": "CREATE_TODO"},
        "details": {"S": "Created todo: Set up AWS RDS"},
        "timestamp": {"S": "2024-01-01 10:00:00"},
        "user_ip": {"S": "10.21.1.100"}
    }' --region eu-central-1
```



**Какие преимущества и недостатки DynamoDB по сравнению с RDS?**

| | DynamoDB | RDS MySQL |
|---|---|---|
| **Модель данных** | NoSQL, документы/ключ-значение | Реляционная, таблицы со схемой |
| **Масштабирование** | Автоматическое, горизонтальное | Вертикальное (upgrade instance) |
| **Производительность** | Миллисекунды при любой нагрузке | Зависит от нагрузки и индексов |
| **Схема** | Гибкая, без фиксированной схемы | Строгая схема, миграции |
| **JOIN-запросы** | Не поддерживаются | Полная поддержка |
| **Транзакции** | Ограниченные | Полные ACID-транзакции |
| **Стоимость** | Pay-per-request, дёшево при низкой нагрузке | Фиксированная стоимость инстанса |

В данном случае DynamoDB идеально подходит для логов активности: схема может меняться (разные типы событий имеют разные атрибуты), записи делаются часто, а сложных запросов с JOIN не требуется.

**Какие сложности при проектировании данных для DynamoDB?**

1. **Отсутствие JOIN** — нельзя связать таблицы как в SQL. Данные нужно денормализовать или делать несколько запросов в коде
2. **Ограниченные запросы** — нет WHERE по произвольному полю без создания GSI (Global Secondary Index)
3. **Проектирование ключей** — неправильный выбор Partition Key приводит к "горячим" разделам и снижению производительности
4. **Нет агрегаций** — нет COUNT, SUM, AVG — нужно считать в приложении
5. **Размер элемента** — максимум 400 KB на один элемент

**Сценарий совместного использования RDS + DynamoDB:**

В приложении Task Manager оба сервиса выполняют свою роль:

- **RDS MySQL** хранит основные бизнес-данные: задачи, категории, пользователи, связи между ними. Здесь важны транзакции (создать задачу + обновить счётчик категории атомарно), JOIN-запросы и строгая схема.

- **DynamoDB** хранит логи активности, сессии пользователей, кэш часто читаемых данных. Здесь важна скорость записи (тысячи событий в секунду), гибкая схема (разные типы событий) и автомасштабирование.

Совместное использование оправдано, т.к. каждая БД используется там, где она сильнее: RDS для сложных реляционных данных, DynamoDB для высоконагруженных операций с простой структурой.

---

## Ответы на контрольные вопросы

| № | Вопрос | Ответ |
|---|---|---|
| 1 | Что такое Subnet Group? | Набор приватных подсетей в разных AZ для размещения RDS. Обеспечивает изоляцию и Multi-AZ возможности |
| 2 | Какие данные видны на реплике? | Те же данные что на мастере — реплика является точной асинхронной копией через binlog |
| 3 | Получилось ли писать на реплику? | Нет — реплика работает в read-only режиме. ERROR 1290: running with --read-only option |
| 4 | Отобразилась ли новая запись на реплике? | Да, через несколько секунд — асинхронная репликация через бинарный лог MySQL |
| 5 | Зачем нужны Read Replicas? | Масштабирование чтения, отказоустойчивость, географическое распределение, изоляция аналитики |
| 6 | Преимущества DynamoDB vs RDS? | DynamoDB: автомасштабирование, гибкая схема, высокая скорость. RDS: JOIN, транзакции, строгая схема |
| 7 | Сложности проектирования DynamoDB? | Нет JOIN, ограниченные запросы, денормализация, проектирование ключей, нет агрегаций |

---

## Список использованных источников

1. [Amazon RDS Documentation](https://docs.aws.amazon.com/rds/)
2. [RDS Read Replicas](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_ReadRepl.html)
3. [Amazon DynamoDB Documentation](https://docs.aws.amazon.com/dynamodb/)
4. [DynamoDB Best Practices](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/best-practices.html)
5. [AWS SDK for PHP — DynamoDB](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/dynamodb-examples.html)
6. [RDS DB Subnet Groups](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_VPC.WorkingWithRDSInstanceinaVPC.html)
7. [MySQL Replication](https://dev.mysql.com/doc/refman/8.0/en/replication.html)

---

## Вывод

В ходе лабораторной работы освоены управляемые базы данных AWS. Создан экземпляр Amazon RDS MySQL в приватной подсети VPC с правильно настроенными Security Groups. Выполнены базовые SQL-операции: созданы таблицы `categories` и `todos` со связью 1-ко-многим, вставлены тестовые данные, выполнены JOIN-запросы.

Создана Read Replica и проверена работа репликации: данные корректно синхронизируются с мастера на реплику, запись на реплику заблокирована. Развёрнуто PHP-приложение Task Manager, которое использует мастер для записи и реплику для чтения — это снижает нагрузку на основной экземпляр.

В дополнительном задании создана таблица DynamoDB `ActivityLog` для логирования действий пользователей. Показаны преимущества совместного использования RDS и DynamoDB в одном приложении: каждая база данных используется там, где она эффективнее.
