# Лабораторная работа №2. Введение в AWS. Вычислительные сервисы

## Описание лабораторной работы

Лабораторная работа посвящена знакомству с основными вычислительными сервисами AWS. В ходе работы были изучены сервисы IAM, EC2, Billing, CloudWatch, а также выполнено развёртывание PHP-приложения в Docker-контейнерах на виртуальной машине Amazon EC2.

---

## Постановка задачи

- Создать IAM-группу и пользователя с правами администратора
- Настроить бюджет Zero-Spend для контроля расходов
- Запустить EC2-инстанс с веб-сервером Nginx
- Изучить инструменты мониторинга и логирования EC2
- Подключиться к инстансу по SSH
- Развернуть PHP-приложение в Docker (nginx + php-fpm + mysql + adminer)
- Остановить EC2-инстанс через AWS CLI

---

## Цель и основные этапы работы

**Цель:** Освоить базовые вычислительные сервисы AWS и получить практические навыки работы с EC2, IAM, Docker.

**Этапы:**
1. Регистрация в AWS и выбор региона
2. Создание IAM-группы и пользователя
3. Настройка бюджета Zero-Spend
4. Запуск EC2-инстанса с User Data скриптом
5. Мониторинг и логирование инстанса
6. Подключение по SSH
7. Развёртывание Docker-приложения (задание 6c)
8. Остановка инстанса через AWS CLI

---

## Практическая часть

### Задание 0. Подготовка среды

Зарегистрирован аккаунт на [aws.amazon.com](https://aws.amazon.com). Выбран регион **EU (Frankfurt) `eu-central-1`** в правом верхнем углу консоли.

> 📸 *[скриншот: выбранный регион EU (Frankfurt) в консоли AWS]*

---

### Задание 1. Создание IAM группы и пользователя

**Создание группы Admins:**

Открыт сервис IAM → Groups → Create New Group.  
Введено имя группы `Admins`.  
Прикреплена политика **AdministratorAccess**.

> 📸 *[скриншот: создание группы Admins с политикой AdministratorAccess]*

**Что делает политика AdministratorAccess?**  
Политика `AdministratorAccess` предоставляет полный доступ ко всем сервисам и ресурсам AWS. Это эквивалент прав суперпользователя — пользователь с данной политикой может создавать, изменять и удалять любые ресурсы в аккаунте. Используется для администраторов, которым необходим неограниченный доступ.

**Создание пользователя:**

Открыт раздел Users → Add user.  
Введено имя пользователя: `cloudstudent`.  
Пользователь привязан к группе `Admins`.  
Включён доступ в AWS Management Console.

> 📸 *[скриншот: созданный пользователь cloudstudent в группе Admins]*

Выполнен выход из root-аккаунта, выполнен вход под пользователем `cloudstudent`.

---

### Задание 2. Настройка Zero-Spend Budget

Открыт сервис **Billing and Cost Management** → Budgets → Create budget.  
Выбран шаблон **Zero spend budget**.  
Параметры:
- Budget name: `ZeroSpend`
- Email recipients: `[мой email]`

Нажата кнопка **Create budget**.

> 📸 *[скриншот: созданный бюджет ZeroSpend]*

После создания бюджета система будет отправлять уведомления на email, если расходы превысят $0.

---

### Задание 3. Создание и запуск EC2 инстанса

Открыт сервис **EC2** → Instances → **Launch instances**.

**Параметры инстанса:**

| Параметр | Значение |
|---|---|
| Name | `webserver` |
| AMI | Amazon Linux 2023 AMI |
| Instance type | `t3.micro` |
| Key pair | `cloudstudent-keypair` (создан новый) |
| Security group | `webserver-sg` |

**Security Group правила:**

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| HTTP | TCP | 80 | 0.0.0.0/0 (любой IP) |
| SSH | TCP | 22 | Мой IP-адрес |

**User Data скрипт:**

```bash
#!/bin/bash
dnf -y update
dnf -y install htop
dnf -y install nginx
systemctl enable nginx
systemctl start nginx
```

**Что такое User Data и какую роль выполняет данный скрипт?**  
User Data — это скрипт, который автоматически выполняется при первом запуске EC2-инстанса (во время начальной загрузки, через сервис `cloud-init`). Он позволяет автоматизировать начальную конфигурацию сервера без ручного входа по SSH.  

Данный скрипт выполняет:
- `dnf -y update` — обновляет все пакеты системы
- `dnf -y install htop` — устанавливает утилиту мониторинга процессов
- `dnf -y install nginx` — устанавливает веб-сервер Nginx
- `systemctl enable nginx` — добавляет Nginx в автозапуск
- `systemctl start nginx` — запускает Nginx

**Для чего используется Nginx?**  
Nginx — это высокопроизводительный веб-сервер и обратный прокси. В данной лабораторной работе Nginx используется для обработки входящих HTTP-запросов на порту 80 и отдачи веб-страниц пользователям. Также Nginx выступает как прокси-сервер, перенаправляя запросы к PHP-FPM для выполнения PHP-кода.

После запуска инстанс получил статус **Running** и **2/2 checks passed**.

> 📸 *[скриншот: инстанс webserver в статусе Running]*
> 📸 *[скриншот: открытая страница Nginx в браузере по http://<Public-IP>]*

---

### Задание 4. Логирование и мониторинг

**Status checks:**

На вкладке **Status checks** отображаются две проверки:
- **System reachability check** — проверяет доступность инфраструктуры AWS (железо и гипервизор). Статус: ✅ passed
- **Instance reachability check** — проверяет доступность операционной системы на уровне инстанса. Статус: ✅ passed

> 📸 *[скриншот: вкладка Status checks — 2/2 checks passed]*

**Мониторинг (CloudWatch):**

На вкладке **Monitoring** отображаются метрики CloudWatch: CPU Utilization, Network In/Out, Disk Read/Write.  
По умолчанию включён **Basic monitoring** — данные поступают раз в 5 минут.

> 📸 *[скриншот: вкладка Monitoring с графиками CloudWatch]*

**В каких случаях важно включать детализированный мониторинг?**  
Детализированный мониторинг (Detailed monitoring, каждую минуту) важно включать в следующих случаях:
- При пиковых нагрузках, когда важно быстро реагировать на скачки CPU или сети
- В production-окружениях с SLA, где критично раннее обнаружение проблем
- При автоматическом масштабировании (Auto Scaling), так как решения о добавлении/удалении инстансов принимаются на основе метрик — чем свежее данные, тем точнее реакция
- При дебаггинге инцидентов, когда нужна детальная картина за короткий промежуток времени

**System Log:**

Открыт через Actions → Monitor and troubleshoot → **Get system log**.  
В логе видны строки инициализации cloud-init и установки nginx из User Data скрипта.

> 📸 *[скриншот: System Log с записями установки nginx]*

**Instance Screenshot:**

Открыт через Actions → Monitor and troubleshoot → **Get instance screenshot**.  
Отображается консоль инстанса — ОС успешно загружена, ошибок нет.

> 📸 *[скриншот: Instance Screenshot]*

---

### Задание 5. Подключение к EC2 инстансу по SSH

```bash
# Перейти в директорию с ключом
cd /path/to/key

# Установить права на ключ
chmod 400 cloudstudent-keypair.pem

# Подключиться к инстансу
ssh -i cloudstudent-keypair.pem ec2-user@<Public-IP>
```

После успешного подключения:

```bash
[ec2-user@ip-xx-xx-xx-xx ~]$ systemctl status nginx
```

> 📸 *[скриншот: успешное SSH-подключение к инстансу]*
> 📸 *[скриншот: вывод systemctl status nginx — active (running)]*

**Почему в AWS нельзя использовать пароль для входа по SSH?**  
В AWS по умолчанию парольная аутентификация по SSH отключена по соображениям безопасности. Пароли уязвимы к брутфорс-атакам — злоумышленники могут перебирать пароли автоматически. Вместо этого используется аутентификация по криптографической паре ключей (RSA/ED25519):
- **Приватный ключ** (`.pem`) хранится у пользователя локально и никуда не передаётся
- **Публичный ключ** записывается на инстанс при создании
- При подключении сервер проверяет, что клиент владеет приватным ключом, не передавая его по сети

Это существенно безопаснее, так как приватный ключ в 2048–4096 бит невозможно подобрать перебором за разумное время.

---

### Задание 6c. Запуск PHP-приложения в Docker

Развёрнуто PHP-приложение **To-Do List** — веб-интерфейс для управления задачами с хранением данных в MySQL.

#### Архитектура

```
Internet → nginx:80 → php-fpm:9000 → mysql:3306
                   adminer:8080 → mysql:3306
```

| Контейнер | Образ | Роль |
|---|---|---|
| nginx | nginx:alpine | Веб-сервер, принимает HTTP-запросы |
| php | php:8.2-fpm | Выполняет PHP-код |
| mysql | mysql:8.0 | База данных |
| adminer | adminer:latest | Веб-интерфейс для управления БД |

#### Установка Docker

```bash
# Установить Docker
sudo dnf -y install docker
sudo systemctl enable docker
sudo systemctl start docker
sudo usermod -aG docker ec2-user

# Переподключиться к инстансу
exit
ssh -i cloudstudent-keypair.pem ec2-user@<Public-IP>

# Проверить версию
docker --version
```

> 📸 *[скриншот: вывод docker --version]*

#### Установка Docker Compose

```bash
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
docker-compose --version
```

#### Структура проекта

```
lab2/
├── docker-compose.yml
├── app/
│   └── index.php
└── nginx/
    └── default.conf
```

#### docker-compose.yml

```yaml
services:

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
    volumes:
      - ./app:/var/www/html
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - php
    restart: unless-stopped

  php:
    image: php:8.2-fpm
    volumes:
      - ./app:/var/www/html
    depends_on:
      mysql:
        condition: service_healthy
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: rootpass
      MYSQL_DATABASE: tododb
      MYSQL_USER: todouser
      MYSQL_PASSWORD: todopass
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-prootpass"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  adminer:
    image: adminer:latest
    ports:
      - "8080:8080"
    depends_on:
      - mysql
    restart: unless-stopped

volumes:
  mysql_data:
```

#### Nginx конфигурация (nginx/default.conf)

```nginx
server {
    listen 80;
    server_name _;

    root /var/www/html;
    index index.php index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

#### Передача файлов на сервер

```bash
# С локального компьютера
scp -i cloudstudent-keypair.pem -r lab2/ ec2-user@<Public-IP>:~/
```

#### Запуск приложения

```bash
cd ~/lab2
docker-compose up -d
```

> 📸 *[скриншот: вывод docker-compose up -d]*

#### Проверка запущенных контейнеров

```bash
docker-compose ps
```

> 📸 *[скриншот: все 4 контейнера в статусе Up]*

#### Результат

- Приложение доступно по адресу: `http://<Public-IP>` — отображается To-Do List с подключением к MySQL
- Adminer доступен по адресу: `http://<Public-IP>:8080`

> 📸 *[скриншот: To-Do List в браузере — добавлено несколько задач]*
> 📸 *[скриншот: Adminer — успешный вход в БД, видна таблица tasks]*

**Подключение к Adminer:**
- System: MySQL
- Server: mysql
- Username: todouser
- Password: todopass
- Database: tododb

---

### Задание 7. Завершение работы

Инстанс остановлен через **AWS CLI**:

```bash
# Установить AWS CLI (если не установлен)
sudo dnf -y install aws-cli

# Настроить credentials
aws configure

# Получить Instance ID
aws ec2 describe-instances --filters "Name=tag:Name,Values=webserver" \
  --query "Reservations[*].Instances[*].InstanceId" --output text

# Остановить инстанс
aws ec2 stop-instances --instance-ids <Instance-ID>
```

> 📸 *[скриншот: вывод команды stop-instances — статус stopping]*
> 📸 *[скриншот: инстанс в статусе stopped в консоли AWS]*

**Чем «Stop» отличается от «Terminate»?**

| | Stop | Terminate |
|---|---|---|
| Инстанс | Останавливается, можно запустить снова | Удаляется безвозвратно |
| EBS (диск) | Сохраняется | Удаляется (по умолчанию) |
| Public IP | Меняется при следующем запуске | Освобождается |
| Данные в памяти | Теряются | Теряются |
| Оплата | Не тарифицируется (только хранилище EBS) | Прекращается полностью |

`Stop` используется при временной паузе — данные на диске сохраняются. `Terminate` — полное уничтожение инстанса и всех связанных ресурсов (если не настроено иное).

---

## Ответы на контрольные вопросы

| № | Вопрос | Ответ |
|---|---|---|
| 1 | Что делает политика AdministratorAccess? | Предоставляет полный доступ ко всем сервисам и ресурсам AWS — эквивалент прав суперпользователя |
| 2 | Что такое User Data и роль скрипта? | Скрипт, выполняемый при первом запуске инстанса. Автоматически обновляет пакеты, устанавливает и запускает Nginx |
| 3 | Для чего используется Nginx? | Высокопроизводительный веб-сервер и обратный прокси для обработки HTTP-запросов и передачи их в PHP-FPM |
| 4 | В каких случаях важен детализированный мониторинг? | При пиковых нагрузках, в production с SLA, при Auto Scaling, при дебаггинге инцидентов |
| 5 | Почему нельзя использовать пароль для SSH? | Пароли уязвимы к брутфорс-атакам. Аутентификация по ключу (RSA) значительно безопаснее |
| 6 | Что делает команда scp? | Secure Copy Protocol — копирует файлы между локальным компьютером и удалённым сервером по зашифрованному SSH-соединению |
| 7 | Чем Stop отличается от Terminate? | Stop — временная остановка с сохранением диска; Terminate — безвозвратное удаление инстанса и ресурсов |

---

## Список использованных источников

1. [AWS EC2 Documentation](https://docs.aws.amazon.com/ec2/)
2. [AWS IAM Documentation](https://docs.aws.amazon.com/iam/)
3. [AWS CLI Reference — stop-instances](https://docs.aws.amazon.com/cli/latest/reference/ec2/stop-instances.html)
4. [Docker Documentation — Compose](https://docs.docker.com/compose/)
5. [Nginx Documentation](https://nginx.org/en/docs/)
6. [PHP-FPM Documentation](https://www.php.net/manual/en/install.fpm.php)
7. [Amazon CloudWatch — EC2 Monitoring](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/using-cloudwatch.html)

---

## Вывод

В ходе лабораторной работы были освоены базовые вычислительные сервисы AWS. Был создан IAM-пользователь с правами администратора, настроен бюджет Zero-Spend для контроля расходов. Запущен EC2-инстанс типа `t3.micro` с Amazon Linux 2023, на котором с помощью User Data скрипта автоматически установлен и запущен Nginx.

Изучены инструменты мониторинга: Status Checks, CloudWatch метрики, System Log и Instance Screenshot. Выполнено SSH-подключение к инстансу и проверена работа веб-сервера.

В рамках задания 6c развёрнуто PHP-приложение (To-Do List) с использованием Docker Compose. Стек включает четыре контейнера: nginx (веб-сервер), php-fpm (выполнение PHP), mysql (база данных) и adminer (веб-интерфейс администрирования БД). Приложение успешно работает и взаимодействует с базой данных.

Работа с EC2 и Docker дала практическое понимание принципов развёртывания веб-приложений в облаке AWS.
