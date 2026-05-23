# Лабораторная работа №6. ELB, Auto Scaling и CloudWatch

## Описание лабораторной работы

Лабораторная работа посвящена созданию отказоустойчивой и автоматически масштабируемой архитектуры в AWS. Развёрнуты: VPC с публичными и приватными подсетями, EC2 веб-сервер с Nginx, AMI, Launch Template, Application Load Balancer, Auto Scaling Group. Проведён нагрузочный тест с наблюдением за автоматическим масштабированием через CloudWatch.

---

## Постановка задачи

- Создать VPC с публичными и приватными подсетями в двух AZ
- Запустить EC2 с Nginx и настроить CloudWatch мониторинг
- Создать AMI на основе настроенного инстанса
- Создать Launch Template для Auto Scaling
- Настроить Target Group и Application Load Balancer
- Создать Auto Scaling Group с политикой масштабирования по CPU
- Провести нагрузочный тест и наблюдать за масштабированием

---

## Цель и основные этапы работы

**Цель:** Освоить механизмы горизонтального масштабирования в AWS: Auto Scaling Group, Application Load Balancer и мониторинг через CloudWatch.

**Этапы:**
1. Создание VPC и подсетей
2. Запуск EC2 с UserData скриптом
3. Создание AMI
4. Создание Launch Template
5. Создание Target Group
6. Создание Application Load Balancer
7. Создание Auto Scaling Group
8. Тестирование Load Balancer
9. Нагрузочный тест и Auto Scaling
10. Очистка ресурсов

---

## Практическая часть

### Шаг 1. Создание VPC и подсетей

Использована VPC из предыдущих лабораторных работ: `student-vpc-k21` (10.21.0.0/16).

Созданы подсети в двух зонах доступности:

| Имя | CIDR | Тип | AZ |
|---|---|---|---|
| `public-subnet-1` | `10.21.1.0/24` | Публичная | eu-central-1a |
| `public-subnet-2` | `10.21.3.0/24` | Публичная | eu-central-1b |
| `private-subnet-1` | `10.21.2.0/24` | Приватная | eu-central-1a |
| `private-subnet-2` | `10.21.4.0/24` | Приватная | eu-central-1b |

Internet Gateway `student-igw-k21` прикреплён к VPC.  
В публичной Route Table добавлен маршрут `0.0.0.0/0 → student-igw-k21`.

> 📸 *[скриншот: VPC с 4 подсетями в двух AZ]*
> 📸 *[скриншот: Route Table с маршрутом к IGW]*

---

### Шаг 2. Создание и настройка виртуальной машины

**Параметры EC2:**

| Параметр | Значение |
|---|---|
| AMI | Amazon Linux 2023 |
| Instance type | `t3.micro` |
| VPC | `student-vpc-k21` |
| Subnet | `public-subnet-1` |
| Auto-assign Public IP | Enable |
| Security Group | `web-sg-k21` (HTTP 80, SSH 22) |
| Detailed CloudWatch monitoring | Enabled |

**Security Group `web-sg-k21`:**

| Тип | Порт | Источник |
|---|---|---|
| SSH | 22 | Мой IP |
| HTTP | 80 | 0.0.0.0/0 |

**Outbound:** All traffic → 0.0.0.0/0

**UserData скрипт (init.sh):**

```bash
#!/bin/bash
dnf update -y
dnf install -y nginx php php-fpm

INSTANCE_ID=$(curl -s http://169.254.169.254/latest/meta-data/instance-id)
AZ=$(curl -s http://169.254.169.254/latest/meta-data/placement/availability-zone)
PRIVATE_IP=$(curl -s http://169.254.169.254/latest/meta-data/local-ipv4)

# Страница с информацией об инстансе
cat > /usr/share/nginx/html/index.html <<EOF
... (показывает Instance ID, AZ, Private IP)
EOF

# Эндпоинт для нагрузочного теста
cat > /usr/share/nginx/html/load.php <<'PHPEOF'
<?php
$seconds = min((int)($_GET['seconds'] ?? 30), 120);
$end = time() + $seconds;
while (time() < $end) {
    $x = 0;
    for ($i = 0; $i < 1000000; $i++) { $x += sqrt($i); }
}
echo json_encode(['status' => 'done', 'instance' => gethostname()]);
PHPEOF

systemctl enable nginx php-fpm
systemctl start nginx php-fpm
```

Дождались статуса **Running** и **2/2 checks passed**.  
Веб-сервер проверен в браузере: `http://<Public-IP>` — отображается страница с Instance ID и AZ.

> 📸 *[скриншот: EC2 инстанс Running, 2/2 checks passed]*
> 📸 *[скриншот: страница сайта в браузере — Instance ID и AZ]*

---

### Шаг 3. Создание AMI

EC2 → выбран инстанс → **Actions → Image and templates → Create image**.

| Параметр | Значение |
|---|---|
| Image name | `project-web-server-ami` |
| No reboot | Unchecked (рекомендуется) |

Ожидание статуса **Available** в разделе AMIs.

> 📸 *[скриншот: AMI project-web-server-ami в статусе Available]*

**Что такое image и чем он отличается от snapshot? Какие варианты использования AMI?**

**Snapshot** — это резервная копия EBS-тома (блочного хранилища) на определённый момент времени. Хранит только данные диска без информации о конфигурации инстанса.

**AMI (Amazon Machine Image)** — полный шаблон для запуска EC2-инстанса, включающий:
- Один или несколько снимков (snapshots) EBS-томов
- Разрешения на запуск
- Маппинг блочных устройств
- Информацию об архитектуре и ОС

Проще говоря: Snapshot — это "слепок диска", AMI — это "слепок всей машины".

**Варианты использования AMI:**
1. **Auto Scaling** — запуск одинаково настроенных инстансов по шаблону
2. **Golden Image** — стандартизированный образ с предустановленным ПО для всей команды
3. **Disaster Recovery** — быстрое восстановление из проверенного образа
4. **Multi-Region** — копирование AMI в другие регионы для глобального деплоя
5. **Blue/Green Deployment** — создание нового AMI для безопасного обновления без даунтайма

---

### Шаг 4. Создание Launch Template

EC2 → **Launch Templates → Create launch template**.

| Параметр | Значение |
|---|---|
| Name | `project-launch-template` |
| AMI | `project-web-server-ami` (My AMIs) |
| Instance type | `t3.micro` |
| Security groups | `web-sg-k21` |
| Detailed CloudWatch monitoring | Enabled |

> 📸 *[скриншот: Launch Template project-launch-template создан]*

**Что такое Launch Template и зачем он нужен? Чем отличается от Launch Configuration?**

**Launch Template** — это версионированный шаблон конфигурации EC2-инстанса, содержащий: AMI, тип инстанса, security groups, key pair, user data и другие параметры. Auto Scaling Group использует его для запуска новых инстансов.

**Отличия от Launch Configuration:**

| | Launch Template | Launch Configuration |
|---|---|---|
| Версионирование | ✅ Поддерживает версии | ❌ Нет версий |
| Изменение | Создаётся новая версия | Нельзя редактировать |
| Spot + On-Demand | ✅ Смешанные типы | ❌ Только один тип |
| Статус | Рекомендуется AWS | Устаревший (deprecated) |
| T2/T3 Unlimited | ✅ Поддерживает | ❌ Нет |

AWS рекомендует использовать Launch Template вместо Launch Configuration во всех новых проектах.

---

### Шаг 5. Создание Target Group

EC2 → **Target Groups → Create target group**.

| Параметр | Значение |
|---|---|
| Target type | Instances |
| Name | `project-target-group` |
| Protocol | HTTP |
| Port | 80 |
| VPC | `student-vpc-k21` |
| Health check path | `/` |

> 📸 *[скриншот: Target Group project-target-group создана]*

**Зачем необходим и какую роль выполняет Target Group?**

Target Group — это логическая группа целей (инстансов, IP-адресов или Lambda-функций), на которые Load Balancer направляет трафик.

Роли Target Group:
1. **Маршрутизация** — ALB направляет входящие запросы к зарегистрированным целям
2. **Health Checks** — регулярно проверяет доступность каждого инстанса (HTTP GET /). Если инстанс не отвечает — ALB исключает его из ротации
3. **Интеграция с Auto Scaling** — новые инстансы ASG автоматически регистрируются в Target Group
4. **Балансировка** — распределяет трафик между здоровыми инстансами (round-robin по умолчанию)

---

### Шаг 6. Создание Application Load Balancer

EC2 → **Load Balancers → Create Load Balancer → Application Load Balancer**.

| Параметр | Значение |
|---|---|
| Name | `project-alb` |
| Scheme | Internet-facing |
| Subnets | `public-subnet-1`, `public-subnet-2` |
| Security Groups | `web-sg-k21` |
| Listener | HTTP:80 |
| Default action | Forward to `project-target-group` |

> 📸 *[скриншот: ALB project-alb в статусе Active]*
> 📸 *[скриншот: Resource map — связи Listener → Rule → Target Group]*

**В чём разница между Internet-facing и Internal?**

| | Internet-facing | Internal |
|---|---|---|
| DNS | Публичный IP | Только приватный IP |
| Доступность | Из интернета | Только внутри VPC/VPN |
| Использование | Фронтенд, публичные API | Микросервисы, внутренние API |

В нашем случае `Internet-facing` — пользователи обращаются к ALB из интернета, ALB перенаправляет трафик на инстансы в приватных подсетях.

**Что такое Default action и какие есть типы?**

Default action — действие, которое ALB выполняет для запросов, не соответствующих ни одному правилу Listener Rule.

Типы Default action:
- **Forward** — перенаправить запрос в Target Group (используется в нашем случае)
- **Redirect** — перенаправить на другой URL (например, HTTP → HTTPS)
- **Fixed response** — вернуть фиксированный HTTP-ответ (например, 503 Maintenance)

---

### Шаг 7. Создание Auto Scaling Group

EC2 → **Auto Scaling Groups → Create Auto Scaling group**.

| Параметр | Значение |
|---|---|
| Name | `project-auto-scaling-group` |
| Launch template | `project-launch-template` |
| VPC | `student-vpc-k21` |
| Subnets | `private-subnet-1`, `private-subnet-2` |
| AZ distribution | Balanced best effort |
| Load balancer | Attach to `project-target-group` |
| Min capacity | 2 |
| Max capacity | 4 |
| Desired capacity | 2 |
| Scaling policy | Target tracking — CPU 50% |
| Instance warm-up | 60 seconds |
| CloudWatch metrics | Enabled |

> 📸 *[скриншот: Auto Scaling Group project-auto-scaling-group создана, 2 инстанса Running]*

**Почему для Auto Scaling Group выбираются приватные подсети?**

Инстансы ASG помещаются в приватные подсети по соображениям безопасности:
- Инстансы не имеют публичных IP и недоступны напрямую из интернета
- Весь входящий трафик проходит только через ALB (единственная точка входа)
- Злоумышленник не может напрямую атаковать отдельные инстансы, минуя Load Balancer
- ALB находится в публичных подсетях и принимает трафик, перенаправляя его в приватные

**Зачем нужна настройка Availability Zone distribution?**

`Balanced best effort` означает, что AWS старается равномерно распределить инстансы между выбранными AZ. Это обеспечивает:
- **Отказоустойчивость** — если одна AZ выйдет из строя, инстансы в другой AZ продолжат работу
- **Равномерная нагрузка** — трафик распределяется между AZ без перекоса
- **Автоматический ребаланс** — при добавлении инстансов AWS учитывает текущее распределение

**Что такое Instance warm-up period и зачем он нужен?**

Instance warm-up period (60 секунд) — это время после запуска нового инстанса, в течение которого его метрики не учитываются при принятии решений о масштабировании.

Зачем нужен:
- Новый инстанс загружается, устанавливает ПО, прогревает кэш — в этот момент его CPU может быть высоким
- Без warm-up период Auto Scaling мог бы посчитать, что нагрузка ещё выше и запустить лишние инстансы
- 60 секунд достаточно для полного старта Nginx и готовности к обслуживанию трафика

---

### Шаг 8. Тестирование Application Load Balancer

Скопировано DNS-имя ALB из консоли.  
Открыт браузер: `http://project-alb-xxx.eu-central-1.elb.amazonaws.com`

Страница загружается — видна информация об инстансе (Instance ID, AZ, Private IP).  
При обновлении страницы Instance ID и AZ периодически меняются.

> 📸 *[скриншот: сайт открыт через DNS ALB — Instance ID инстанса 1]*
> 📸 *[скриншот: после обновления — другой Instance ID (инстанс 2)]*

**Какие IP-адреса вы видите и почему?**

В ответах отображаются **приватные IP-адреса** инстансов (из диапазона 10.21.2.x и 10.21.4.x). При каждом обновлении страницы ALB направляет запрос к разным инстансам по алгоритму round-robin, поэтому Instance ID и IP меняются. Публичных IP у инстансов нет — они находятся в приватных подсетях и доступны только через ALB.

---

### Шаг 9. Тестирование Auto Scaling

Открыт **CloudWatch → Alarms** — автоматически созданы алармы:
- `TargetTracking-project-auto-scaling-group-AlarmHigh-...` (CPU > 50%)
- `TargetTracking-project-auto-scaling-group-AlarmLow-...` (CPU < 45%)

Начальный CPU: ~0-1% (норма).

> 📸 *[скриншот: CloudWatch Alarm — начальный CPU ~0%]*

**Запуск нагрузочного теста:**

```bash
# Способ 1: скрипт с параметрами
chmod +x curl.sh
./curl.sh project-alb-xxx.eu-central-1.elb.amazonaws.com 10 120

# Способ 2: Apache Benchmark
./curl.sh project-alb-xxx.eu-central-1.elb.amazonaws.com 10 120 --ab

# Способ 3: браузер (6-7 вкладок)
http://project-alb-xxx.eu-central-1.elb.amazonaws.com/load?seconds=60
```

**Скрипт curl.sh** принимает параметры из командной строки:
- `$1` — DNS-имя ALB (обязательный)
- `$2` — количество параллельных потоков (по умолчанию: 5)
- `$3` — длительность нагрузки в секундах (по умолчанию: 60)
- `$4` — режим: `--ab` для Apache Benchmark, `--hey` для hey

Через 2-3 минуты CloudWatch зафиксировал рост CPU выше 50%.  
Аларм перешёл в состояние **In alarm** (красный).

> 📸 *[скриншот: CloudWatch Alarm — CPU поднялся выше 50%, статус In alarm]*
> 📸 *[скриншот: график CPU Utilization в CloudWatch с видимым пиком]*

Auto Scaling Group запустила дополнительные инстансы (с 2 до 3-4).

> 📸 *[скриншот: EC2 Instances — 3-4 инстанса Running во время нагрузки]*
> 📸 *[скриншот: Auto Scaling Group — Activity History, запись о масштабировании]*

**Какую роль в этом процессе сыграл Auto Scaling?**

Auto Scaling сыграл ключевую роль в обеспечении производительности под нагрузкой:

1. **CloudWatch** отслеживал метрику CPU Utilization на инстансах ASG
2. Когда средний CPU превысил **50%** (порог политики), CloudWatch Alarm перешёл в `In alarm`
3. **Auto Scaling Policy** (Target Tracking) рассчитала, сколько инстансов нужно добавить для снижения CPU до целевого значения
4. ASG запустила новые инстансы по **Launch Template** (из нашего AMI)
5. Новые инстансы прошли **warm-up период** (60 сек) и зарегистрировались в **Target Group**
6. ALB начал распределять трафик на все доступные инстансы
7. Нагрузка распределилась, CPU снизился

После остановки теста процесс повторился в обратном направлении — лишние инстансы были автоматически завершены.

---

### Шаг 10. Очистка ресурсов

Ресурсы удалены в следующем порядке:

1. Остановлен нагрузочный тест (Ctrl+C / закрыты вкладки)
2. **Load Balancers** → `project-alb` → Delete
3. **Target Groups** → `project-target-group` → Delete
4. **Auto Scaling Groups** → `project-auto-scaling-group` → Delete
5. **EC2 Instances** → все инстансы → Terminate
6. **AMIs** → `project-web-server-ami` → Deregister (+ удалены связанные snapshots)
7. **Launch Templates** → `project-launch-template` → Delete
8. **VPC** → удалены подсети, IGW и VPC

> 📸 *[скриншот: все EC2 инстансы в статусе Terminated]*

---

## Ответы на контрольные вопросы

| № | Вопрос | Ответ |
|---|---|---|
| 1 | Чем AMI отличается от Snapshot? | Snapshot — слепок EBS-диска. AMI — полный шаблон машины (snapshots + конфигурация + разрешения) |
| 2 | Варианты использования AMI? | Auto Scaling, Golden Image, Disaster Recovery, Multi-Region, Blue/Green Deployment |
| 3 | Что такое Launch Template? Отличие от Launch Configuration? | Версионированный шаблон конфигурации EC2. В отличие от устаревшего Launch Configuration поддерживает версии, смешанные типы инстансов и рекомендован AWS |
| 4 | Роль Target Group? | Логическая группа целей для ALB: маршрутизация трафика, health checks, интеграция с ASG |
| 5 | Internet-facing vs Internal? | Internet-facing — публичный DNS, доступен из интернета. Internal — только внутри VPC |
| 6 | Что такое Default action? | Действие ALB для запросов без matching правила: Forward, Redirect или Fixed response |
| 7 | Почему ASG в приватных подсетях? | Безопасность: инстансы недоступны напрямую из интернета, весь трафик через ALB |
| 8 | Зачем AZ distribution? | Равномерное распределение инстансов между AZ для отказоустойчивости |
| 9 | Что такое Instance warm-up period? | Период после старта инстанса, когда его метрики не учитываются ASG. Предотвращает лишнее масштабирование при инициализации |
| 10 | Какие IP видны при обращении через ALB? | Приватные IP инстансов. При каждом запросе ALB направляет к разному инстансу (round-robin) |
| 11 | Роль Auto Scaling в нагрузочном тесте? | CloudWatch зафиксировал рост CPU → Alarm → ASG запустила новые инстансы → ALB распределил нагрузку |

---

## Список использованных источников

1. [AWS Auto Scaling Documentation](https://docs.aws.amazon.com/autoscaling/)
2. [Application Load Balancer](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/)
3. [Amazon CloudWatch Alarms](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/AlarmThatSendsEmail.html)
4. [EC2 Launch Templates](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/ec2-launch-templates.html)
5. [Target Tracking Scaling](https://docs.aws.amazon.com/autoscaling/ec2/userguide/as-scaling-target-tracking.html)
6. [AMI Documentation](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/AMIs.html)
7. [VPC with Public and Private Subnets](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Scenario2.html)

---

## Вывод

В ходе лабораторной работы создана полноценная отказоустойчивая и автоматически масштабируемая архитектура AWS. Настроены VPC с публичными и приватными подсетями в двух зонах доступности, что обеспечивает изоляцию и отказоустойчивость.

Развёрнут EC2 с Nginx, на основе которого создан AMI и Launch Template — шаблон для автоматического запуска одинаково настроенных инстансов. Application Load Balancer распределяет входящий трафик между инстансами в приватных подсетях, проверяя их доступность через Health Checks.

Auto Scaling Group с политикой Target Tracking (CPU 50%) автоматически масштабировала инфраструктуру: при росте нагрузки во время нагрузочного теста количество инстансов увеличилось с 2 до 4, а после снижения нагрузки — автоматически уменьшилось. CloudWatch обеспечил мониторинг и алертинг, связав метрики с действиями масштабирования.

Разработан скрипт нагрузочного тестирования `curl.sh` с поддержкой параметров командной строки и Apache Benchmark.
