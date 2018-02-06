# api-example
Пример реализации REST-full API с использованием CURL

### Пример вызова

```php
$vds = new ApiVDS('api.test.ru', 443);
$data = $vds->add('myFirstVds', 'Для тестов', 'ubuntu1404', 'x64', [
    'cpu_count' => 2, // 2 ядра
    'hdd_quota' => 5 * pow(1024, 3), // 5 ГБ
    'memory'    => 2 * pow(1024, 3), // 2 ГБ
]);

$vds->status($data['id']);
```
