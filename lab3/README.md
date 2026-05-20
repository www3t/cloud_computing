# Лабораторная работа №3. Виртуальные сети в AWS (VPC)

## Описание лабораторной работы

Лабораторная работа посвящена созданию и настройке виртуальной сети (VPC) в AWS. В ходе работы были созданы публичная и приватная подсети, настроены Internet Gateway, NAT Gateway, таблицы маршрутов и Security Groups, а также развёрнуты три EC2-инстанса: веб-сервер, сервер базы данных и Bastion Host.

---

## Постановка задачи

- Создать VPC с двумя подсетями (публичной и приватной)
- Настроить Internet Gateway для публичного доступа
- Настроить NAT Gateway для выхода приватных ресурсов в интернет
- Создать таблицы маршрутов для каждой подсети
- Настроить Security Groups для веб-сервера, БД и Bastion Host
- Развернуть три EC2-инстанса в соответствующих подсетях
- Проверить связность между инстансами и доступ в интернет

---

## Цель и основные этапы работы

**Цель:** Освоить создание изолированных виртуальных сетей в AWS, научиться управлять маршрутизацией и безопасностью на сетевом уровне.

**Этапы:**
1. Создание VPC
2. Создание Internet Gateway и привязка к VPC
3. Создание публичной и приватной подсетей
4. Создание таблиц маршрутов
5. Создание Elastic IP и NAT Gateway
6. Создание Security Groups
7. Запуск трёх EC2-инстансов
8. Проверка работы сети
9. Подключение к приватной подсети через Bastion Host
10. Завершение работы и удаление ресурсов

---

## Практическая часть

### Шаг 1. Подготовка среды

Выполнен вход в AWS Management Console под IAM-пользователем `cloudstudent`.  
Регион установлен на **Frankfurt (eu-central-1)**.  
В строке поиска открыт сервис **VPC**.

> 📸 *[скриншот: консоль AWS, регион eu-central-1]*

---

### Шаг 2. Создание VPC

В левой панели выбрано **Your VPCs → Create VPC**.

**Параметры:**

| Параметр | Значение |
|---|---|
| Name tag | `student-vpc-k21` |
| IPv4 CIDR block | `10.21.0.0/16` |
| Tenancy | Default |

Нажата кнопка **Create VPC**.

> 📸 *[скриншот: созданная VPC student-vpc-k21 с CIDR 10.21.0.0/16]*

**Что обозначает маска /16? И почему нельзя использовать, например, /8?**

Маска `/16` означает, что первые 16 бит IP-адреса фиксированы (сетевая часть), а оставшиеся 16 бит — для адресов хостов. В диапазоне `10.21.0.0/16` доступно **65 536 IP-адресов** (2¹⁶).

Маску `/8` нельзя использовать для VPC по нескольким причинам:
- AWS ограничивает размер VPC — допустимые маски от `/16` до `/28`
- Диапазон `/8` содержит 16 миллионов адресов, что избыточно и неэффективно для одной VPC
- Большие блоки сложнее администрировать и они конфликтуют с корпоративными сетями

---

### Шаг 3. Создание Internet Gateway (IGW)

В левой панели выбрано **Internet Gateways → Create internet gateway**.

**Параметры:**

| Параметр | Значение |
|---|---|
| Name tag | `student-igw-k21` |

Нажата кнопка **Create internet gateway**.

**Привязка IGW к VPC:**

Выбран созданный IGW → **Actions → Attach to VPC** → выбрана `student-vpc-k21` → подтверждено.

> 📸 *[скриншот: IGW student-igw-k21 в статусе Attached к student-vpc-k21]*

---

### Шаг 4. Создание подсетей

#### Шаг 4.1. Публичная подсеть

В левой панели выбрано **Subnets → Create subnet**.

**Параметры:**

| Параметр | Значение |
|---|---|
| VPC ID | `student-vpc-k21` |
| Subnet name | `public-subnet-k21` |
| Availability Zone | `eu-central-1a` |
| IPv4 CIDR block | `10.21.1.0/24` |

> 📸 *[скриншот: созданная публичная подсеть public-subnet-k21]*

**Является ли подсеть "публичной" на данный момент? Почему?**

Нет, на данный момент подсеть **не является публичной**. Сама по себе подсеть — это просто диапазон IP-адресов внутри VPC. Чтобы подсеть стала публичной, необходимо выполнить два условия:
1. Привязать к ней таблицу маршрутов с маршрутом `0.0.0.0/0 → Internet Gateway`
2. Инстансам в ней должны быть назначены публичные IP-адреса

Пока ни одно из этих условий не выполнено — подсеть "публичная" только по имени.

#### Шаг 4.2. Приватная подсеть

Нажата кнопка **Create subnet**.

**Параметры:**

| Параметр | Значение |
|---|---|
| VPC ID | `student-vpc-k21` |
| Subnet name | `private-subnet-k21` |
| Availability Zone | `eu-central-1b` |
| IPv4 CIDR block | `10.21.2.0/24` |

> 📸 *[скриншот: созданная приватная подсеть private-subnet-k21]*

**Является ли подсеть "приватной" на данный момент? Почему?**

Формально — да, по факту — пока обе подсети одинаковы. Подсеть становится по-настоящему приватной, когда её таблица маршрутов **не содержит** маршрута к Internet Gateway. В текущем состоянии обе подсети используют основную таблицу маршрутов VPC, которая не имеет маршрута в интернет — значит обе пока "приватные" в техническом смысле. Разделение произойдёт на шаге 5.

---

### Шаг 5. Создание таблиц маршрутов

#### Шаг 5.1. Публичная таблица маршрутов

В левой панели выбрано **Route Tables → Create route table**.

**Параметры:**

| Параметр | Значение |
|---|---|
| Name tag | `public-rt-k21` |
| VPC | `student-vpc-k21` |

Нажата кнопка **Create route table**.

**Добавление маршрута к IGW:**

Вкладка **Routes → Edit routes → Add route**:
- Destination: `0.0.0.0/0`
- Target: `student-igw-k21`

Нажата кнопка **Save changes**.

**Привязка к публичной подсети:**

Вкладка **Subnet associations → Edit subnet associations** → отмечена `public-subnet-k21` → **Save associations**.

> 📸 *[скриншот: таблица маршрутов public-rt-k21 с маршрутом 0.0.0.0/0 → IGW]*
> 📸 *[скриншот: привязка public-subnet-k21 к public-rt-k21]*

**Зачем необходимо привязать таблицу маршрутов к подсети?**

Таблица маршрутов определяет, как маршрутизируется трафик из подсети. Без явной привязки подсеть использует основную (main) таблицу маршрутов VPC, которая не имеет маршрута к IGW. Привязав `public-rt-k21` с маршрутом `0.0.0.0/0 → IGW`, мы указываем: весь трафик из этой подсети, не предназначенный для внутренних адресов VPC, направляется в интернет через IGW. Именно это и делает подсеть публичной.

#### Шаг 5.2. Приватная таблица маршрутов

Нажата кнопка **Create route table**.

**Параметры:**

| Параметр | Значение |
|---|---|
| Name tag | `private-rt-k21` |
| VPC | `student-vpc-k21` |

Нажата кнопка **Create route table**.

Вкладка **Subnet associations → Edit subnet associations** → отмечена `private-subnet-k21` → **Save associations**.

> 📸 *[скриншот: таблица маршрутов private-rt-k21 привязана к private-subnet-k21]*

На данном этапе приватная подсеть не имеет выхода в интернет — маршрут к NAT Gateway будет добавлен после его создания.

---

### Шаг 6. Создание NAT Gateway

**Как работает NAT Gateway?**

NAT Gateway (Network Address Translation) позволяет инстансам в приватной подсети инициировать исходящие соединения в интернет, оставаясь при этом недоступными снаружи. Принцип работы:

1. Инстанс в приватной подсети отправляет запрос (например, `dnf update`)
2. Трафик направляется в NAT Gateway (через маршрут `0.0.0.0/0 → NAT GW` в приватной таблице)
3. NAT Gateway заменяет исходный приватный IP на свой публичный Elastic IP
4. Запрос уходит в интернет через IGW от имени NAT Gateway
5. Ответ возвращается на Elastic IP NAT Gateway, который перенаправляет его обратно в приватную подсеть

Снаружи невозможно инициировать соединение с инстансами в приватной подсети — NAT работает только в одну сторону (outbound).

#### Шаг 6.1. Создание Elastic IP

В левой панели выбрано **Elastic IPs → Allocate Elastic IP address**.  
Нажата кнопка **Allocate**.

> 📸 *[скриншот: выделенный Elastic IP]*

#### Шаг 6.2. Создание NAT Gateway

В левой панели выбрано **NAT Gateways → Create NAT gateway**.

**Параметры:**

| Параметр | Значение |
|---|---|
| Name tag | `nat-gateway-k21` |
| Subnet | `public-subnet-k21` |
| Connectivity type | Public |
| Elastic IP | выбран созданный EIP |

Нажата кнопка **Create NAT gateway**.  
Ожидание статуса **Available** (~2-3 минуты).

> 📸 *[скриншот: NAT Gateway nat-gateway-k21 в статусе Available]*

#### Шаг 6.3. Обновление приватной таблицы маршрутов

Выбрана таблица `private-rt-k21` → вкладка **Routes → Edit routes → Add route**:
- Destination: `0.0.0.0/0`
- Target: `nat-gateway-k21`

Нажата кнопка **Save changes**.

> 📸 *[скриншот: приватная таблица маршрутов с маршрутом 0.0.0.0/0 → NAT Gateway]*

---

### Шаг 7. Создание Security Groups

#### web-sg-k21 (для веб-сервера)

**Inbound rules:**

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| HTTP | TCP | 80 | 0.0.0.0/0 |
| HTTPS | TCP | 443 | 0.0.0.0/0 |

> 📸 *[скриншот: Security Group web-sg-k21 с правилами HTTP и HTTPS]*

#### bastion-sg-k21 (для Bastion Host)

**Inbound rules:**

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| SSH | TCP | 22 | Мой IP-адрес |

> 📸 *[скриншот: Security Group bastion-sg-k21 с правилом SSH только с моего IP]*

#### db-sg-k21 (для сервера БД)

**Inbound rules:**

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| MySQL/Aurora | TCP | 3306 | web-sg-k21 |
| MySQL/Aurora | TCP | 3306 | bastion-sg-k21 |
| SSH | TCP | 22 | bastion-sg-k21 |

> 📸 *[скриншот: Security Group db-sg-k21 с правилами MySQL и SSH]*

**Что такое Bastion Host и зачем он нужен в архитектуре с приватными подсетями?**

Bastion Host (или Jump Host) — это специальный EC2-инстанс, расположенный в **публичной** подсети, который служит единственной точкой входа для SSH-доступа к инстансам в **приватной** подсети.

Зачем он нужен:
- Инстансы в приватной подсети не имеют публичных IP-адресов, поэтому напрямую к ним подключиться нельзя
- Bastion Host выступает "шлюзом" — сначала подключаемся к нему, затем с него — к приватным инстансам
- Это повышает безопасность: SSH-порт открыт только на одном хосте (bastion), а не на всех серверах
- Все SSH-соединения логируются в одном месте, что упрощает аудит доступа
- В случае компрометации — изолируем только Bastion Host, не затрагивая остальную инфраструктуру

---

### Шаг 8. Создание EC2-инстансов

Для всех инстансов использовались общие параметры:
- AMI: Amazon Linux 2023 AMI
- Instance type: `t3.micro`
- Key pair: `student-key-k21` (создан новый, скачан .pem файл)
- Storage: 8 ГБ (по умолчанию)

#### web-server

| Параметр | Значение |
|---|---|
| Name | `web-server` |
| VPC | `student-vpc-k21` |
| Subnet | `public-subnet-k21` |
| Auto-assign Public IP | Enable |
| Security Group | `web-sg-k21` |

**User Data:**
```bash
#!/bin/bash
dnf install -y httpd php
echo "<?php phpinfo(); ?>" > /var/www/html/index.php
systemctl enable httpd
systemctl start httpd
```

#### db-server

| Параметр | Значение |
|---|---|
| Name | `db-server` |
| VPC | `student-vpc-k21` |
| Subnet | `private-subnet-k21` |
| Auto-assign Public IP | Disable |
| Security Group | `db-sg-k21` |

**User Data:**
```bash
#!/bin/bash
dnf install -y mariadb105-server
systemctl enable mariadb
systemctl start mariadb
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'StrongPassword123!'; FLUSH PRIVILEGES;"
```

#### bastion-host

| Параметр | Значение |
|---|---|
| Name | `bastion-host` |
| VPC | `student-vpc-k21` |
| Subnet | `public-subnet-k21` |
| Auto-assign Public IP | Enable |
| Security Group | `bastion-sg-k21` |

**User Data:**
```bash
#!/bin/bash
dnf install -y mariadb105
```

> 📸 *[скриншот: все три инстанса в статусе Running]*
> 📸 *[скриншот: web-server — публичный IP виден, Auto-assign Public IP: Enabled]*
> 📸 *[скриншот: db-server — публичного IP нет, подсеть private-subnet-k21]*

---

### Шаг 9. Проверка работы

**Проверка веб-сервера:**

Открыт браузер, введён адрес `http://<web-server-Public-IP>`.  
Отобразилась страница с информацией PHP (`phpinfo()`).

> 📸 *[скриншот: страница phpinfo() в браузере по публичному IP web-server]*

**Подключение к Bastion Host по SSH:**

```bash
chmod 400 student-key-k21.pem
ssh -i student-key-k21.pem ec2-user@<Bastion-Host-Public-IP>
```

**Проверка интернета с Bastion Host:**

```bash
ping -c 4 google.com
```

> 📸 *[скриншот: успешный ping google.com с bastion-host — 4/4 packets]*

Пинги успешны — публичная подсеть и IGW настроены правильно.

**Подключение к db-server через Bastion Host:**

```bash
mysql -h <DB-Server-Private-IP> -u root -p
# Пароль: StrongPassword123!
```

> 📸 *[скриншот: успешное подключение к MySQL на db-server с bastion-host]*

Подключение успешно — приватная подсеть и Security Groups настроены правильно.

---

### Шаг 10. Подключение в приватную подсеть через Bastion Host (SSH Agent Forwarding)

**Запуск SSH Agent и добавление ключа:**

```bash
eval "$(ssh-agent -s)"
ssh-add student-key-k21.pem
```

**Подключение к db-server через bastion-host:**

```bash
ssh -A -J ec2-user@<Bastion-Host-Public-IP> ec2-user@<DB-Server-Private-IP>
```

> 📸 *[скриншот: успешное подключение к db-server через bastion через SSH Agent Forwarding]*

**Что делает опция `-A` и `-J`?**

- `-A` (Agent Forwarding) — пробрасывает SSH-агент с локальной машины на Bastion Host. Это означает, что находясь на bastion, можно использовать приватный ключ с локальной машины для дальнейших SSH-соединений, не копируя ключ на сервер. Ключ остаётся только локально — это безопасно.

- `-J` (Jump Host / ProxyJump) — указывает промежуточный хост (Jump Host), через который нужно подключиться к целевому хосту. Команда `ssh -J user@bastion user@db-server` автоматически создаёт туннель: локальная машина → bastion → db-server, не требуя ручного двухэтапного подключения.

**Обновление системы на db-server (проверка NAT Gateway):**

```bash
sudo dnf update -y
sudo dnf install -y htop
```

> 📸 *[скриншот: успешная установка htop на db-server — NAT Gateway работает]*

Обновление прошло успешно — приватная подсеть имеет выход в интернет через NAT Gateway.

**Подключение к MySQL:**

```bash
mysql -u root -p
# Пароль: StrongPassword123!
```

> 📸 *[скриншот: успешный вход в MySQL на db-server]*

**Завершение сессий:**

```bash
exit        # выход из MySQL
exit        # выход из db-server
exit        # выход из bastion-host

# Завершение SSH Agent на локальной машине
ssh-agent -k
```

---

### Завершение работы и удаление ресурсов

Ресурсы удалены в следующем порядке (чтобы избежать ошибок зависимостей):

1. **EC2-инстансы** — выбраны все три → Actions → Terminate instance
2. **NAT Gateway** — выбран `nat-gateway-k21` → Actions → Delete NAT gateway → ожидание удаления
3. **Elastic IP** — VPC → Elastic IPs → Actions → Release Elastic IP addresses
4. **Security Groups** — удалены `web-sg-k21`, `bastion-sg-k21`, `db-sg-k21`
5. **Internet Gateway** — Actions → Detach from VPC → затем Delete internet gateway
6. **VPC** — Actions → Delete VPC

> 📸 *[скриншот: все EC2-инстансы в статусе Terminated]*
> 📸 *[скриншот: VPC student-vpc-k21 удалена]*

---

## Ответы на контрольные вопросы

| № | Вопрос | Ответ |
|---|---|---|
| 1 | Что обозначает маска /16? | Первые 16 бит — сетевая часть, доступно 65 536 адресов. /8 запрещён в AWS (минимум /16) и избыточен |
| 2 | Является ли созданная публичная подсеть "публичной"? | Нет — без таблицы маршрутов с IGW и публичного IP она неотличима от приватной |
| 3 | Является ли созданная приватная подсеть "приватной"? | Формально да — без маршрута к IGW трафик не выходит в интернет. Разница закрепляется таблицами маршрутов |
| 4 | Зачем привязывать таблицу маршрутов к подсети? | Без явной привязки используется main route table. Привязка к public-rt с IGW делает подсеть публичной |
| 5 | Как работает NAT Gateway? | Заменяет приватные IP на публичный Elastic IP для исходящего трафика. Входящие соединения извне невозможны |
| 6 | Что такое Bastion Host? | EC2 в публичной подсети — единственная точка SSH-входа к приватным ресурсам. Повышает безопасность и упрощает аудит |
| 7 | Что делает опция -A и -J? | -A пробрасывает SSH-агент (ключ не копируется на сервер); -J указывает Jump Host для автоматического туннеля |

---

## Список использованных источников

1. [AWS VPC Documentation](https://docs.aws.amazon.com/vpc/)
2. [AWS Internet Gateway](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Internet_Gateway.html)
3. [AWS NAT Gateway](https://docs.aws.amazon.com/vpc/latest/userguide/vpc-nat-gateway.html)
4. [AWS Route Tables](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Route_Tables.html)
5. [AWS Security Groups](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_SecurityGroups.html)
6. [SSH Agent Forwarding](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/using-ssh-agent-forwarding)
7. [AWS EC2 User Data](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/user-data.html)

---

## Вывод

В ходе лабораторной работы была создана полноценная виртуальная сеть в AWS. Созданы VPC `student-vpc-k21` с адресным пространством `10.21.0.0/16`, публичная подсеть `10.21.1.0/24` и приватная подсеть `10.21.2.0/24`.

Настроен Internet Gateway для публичного доступа и NAT Gateway для исходящего интернет-трафика из приватной подсети. Таблицы маршрутов чётко разделили трафик: публичная подсеть направляет внешний трафик через IGW, приватная — через NAT Gateway.

Созданы три EC2-инстанса с разными ролями: веб-сервер в публичной подсети (доступен по HTTP), сервер БД в приватной подсети (изолирован от внешнего доступа), и Bastion Host для безопасного SSH-доступа к приватным ресурсам. Security Groups обеспечивают минимально необходимый уровень доступа между компонентами.

Проверена работа SSH Agent Forwarding через Bastion Host, успешное подключение к MySQL и обновление пакетов на приватном инстансе через NAT Gateway.
