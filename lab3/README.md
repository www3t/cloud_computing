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

> <img width="2017" height="1121" alt="vpc" src="https://github.com/user-attachments/assets/9ffec36b-64a6-4d00-b8f5-93c6966c8418" />


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

> <img width="1746" height="831" alt="created-vpc" src="https://github.com/user-attachments/assets/b9aadc0e-1e41-4cf0-96fe-07185f11dccb" />


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

> <img width="1907" height="551" alt="attached vpc" src="https://github.com/user-attachments/assets/f930b981-c080-497e-9418-e5003b12a39e" />


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

> <img width="1746" height="831" alt="created-vpc" src="https://github.com/user-attachments/assets/dd4a2426-1f57-433b-91b7-a1aeb8f5d813" />


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

> <img width="2196" height="370" alt="subnet-association" src="https://github.com/user-attachments/assets/04d23e8c-8caa-4a67-9052-14d5ad3ef106" />


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


<img width="2307" height="522" alt="created-subnet" src="https://github.com/user-attachments/assets/a35b597e-6cae-46ef-b938-50047f47f9ef" />


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

> <img width="1983" height="772" alt="route-table" src="https://github.com/user-attachments/assets/28b9ecfd-ae27-44e7-9c68-29d7308fcdcb" />


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

><img width="2293" height="388" alt="elastic_ip" src="https://github.com/user-attachments/assets/32057c09-c4a9-47fd-a8b1-98faea0f7e4b" />


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

> <img width="1757" height="617" alt="nat-gateway" src="https://github.com/user-attachments/assets/c003dc04-9eae-4eca-babf-b76be25b45a5" />


#### Шаг 6.3. Обновление приватной таблицы маршрутов

Выбрана таблица `private-rt-k21` → вкладка **Routes → Edit routes → Add route**:
- Destination: `0.0.0.0/0`
- Target: `nat-gateway-k21`

Нажата кнопка **Save changes**.

> <img width="1820" height="686" alt="updated-route-table-with-nat" src="https://github.com/user-attachments/assets/de40fe19-5db8-4129-8c83-4b6068312746" />


---

### Шаг 7. Создание Security Groups

#### web-sg-k21 (для веб-сервера)

**Inbound rules:**

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| HTTP | TCP | 80 | 0.0.0.0/0 |
| HTTPS | TCP | 443 | 0.0.0.0/0 |

> 

#### bastion-sg-k21 (для Bastion Host)

**Inbound rules:**

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| SSH | TCP | 22 | Мой IP-адрес |

> 

#### db-sg-k21 (для сервера БД)

**Inbound rules:**

| Тип | Протокол | Порт | Источник |
|---|---|---|---|
| MySQL/Aurora | TCP | 3306 | web-sg-k21 |
| MySQL/Aurora | TCP | 3306 | bastion-sg-k21 |
| SSH | TCP | 22 | bastion-sg-k21 |

> <img width="1832" height="828" alt="ACL-web-sg" src="https://github.com/user-attachments/assets/84ab99d6-a6d7-49a4-ad75-f84ef49fb341" />


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

<img width="2200" height="412" alt="3 inst status checked" src="https://github.com/user-attachments/assets/84d5a775-39f8-427a-8945-f95d5bc85370" />


---

### Шаг 9. Проверка работы

**Проверка веб-сервера:**

Открыт браузер, введён адрес `http://<web-server-Public-IP>`.  
Отобразилась страница с информацией PHP (`phpinfo()`).

> <img width="1520" height="1078" alt="php_info" src="https://github.com/user-attachments/assets/8de9fac2-65d9-4de6-832a-63a483b40843" />


**Подключение к Bastion Host по SSH:**

```bash
chmod 400 student-key-k21.pem
ssh -i student-key-k21.pem ec2-user@<Bastion-Host-Public-IP>
```

**Проверка интернета с Bastion Host:**

```bash
ping -c 4 google.com
```

> <img width="1052" height="520" alt="google-ping" src="https://github.com/user-attachments/assets/289d82e9-6c29-4393-b7cd-f8a1d6b11880" />


Пинги успешны — публичная подсеть и IGW настроены правильно.

**Подключение к db-server через Bastion Host:**

```bash
mysql -h <DB-Server-Private-IP> -u root -p
# Пароль: StrongPassword123!
```

> <img width="687" height="58" alt="db-check" src="https://github.com/user-attachments/assets/f596a1eb-fe51-4501-a45d-578dcde504bc" />


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

> <img width="687" height="58" alt="db-check" src="https://github.com/user-attachments/assets/1efe0446-6f7c-4431-a32f-4e07031916a0" />


**Что делает опция `-A` и `-J`?**

- `-A` (Agent Forwarding) — пробрасывает SSH-агент с локальной машины на Bastion Host. Это означает, что находясь на bastion, можно использовать приватный ключ с локальной машины для дальнейших SSH-соединений, не копируя ключ на сервер. Ключ остаётся только локально — это безопасно.

- `-J` (Jump Host / ProxyJump) — указывает промежуточный хост (Jump Host), через который нужно подключиться к целевому хосту. Команда `ssh -J user@bastion user@db-server` автоматически создаёт туннель: локальная машина → bastion → db-server, не требуя ручного двухэтапного подключения.

**Обновление системы на db-server (проверка NAT Gateway):**

```bash
sudo dnf update -y
sudo dnf install -y htop
```

> <img width="2486" height="1051" alt="htop check" src="https://github.com/user-attachments/assets/5a241f15-79bb-46bc-80b8-072aa5083f87" />


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

<img width="1612" height="640" alt="Screenshot 2026-05-21 002932" src="https://github.com/user-attachments/assets/128f0e43-60ef-4ceb-b656-7056fdbfdbd4" />
<img width="2223" height="296" alt="natg-deleted" src="https://github.com/user-attachments/assets/5e165de8-f052-4f63-b509-b9667f35c88c" />


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
