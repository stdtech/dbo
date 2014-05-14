dbo
===

Simple ORM for PHP 5.3

Exaple of usage:

Создаем структуру таблиц:

```php
$T["pack"] = array(
    "table" => "pack",
    "key" => "id",
    "fields" => array(
        "id" => "int",
        "name" => "char",
        "date_created" => "date",
        "desired_size" => "int",
        "pages_min" => "int",
        "pages_max" => "int",
        "theme_id" => "int",
        "is_full" => "int"
    )
);
```

Инициализируем коннект к БД и "скармливаем БД"

```php
$db = new DBObject($config);
$db->init_tables($T);
```

Так выглядит одна строка "вынутая" из базы - ассоциативный массив

```php
$row = array(
    "id" => 1,
    "name" => "Alex",
    "date_created" => "2014-03-03 11:12:33",
    "desired_size" => 15,
    ...
);
```

Получить из таблицы "pack" строчки по первичному ключу (колонка "id")

```php
$rows = $db->get('pack', 3); 
```

Вернется:	

```php
array(
	0 => array(
	    "id" => 3,
	    "name" => "Mike",
	    "date_created" => "2014-03-03 11:12:33",
	    "desired_size" => 15,
		...
	)
)
```

Получить из таблицы "pack" строчки по первичному где колонка "name" == "Alex"

```php
$filter = array(
	"name" => "Alex"
);
$db->get('pack', $filter) 
```

Вернется:

```php
array(
	0 => array(
	    "id" => 1,
	    "name" => "Alex",
	    "date_created" => "2014-03-03 11:12:33",
	    "desired_size" => 15,
		...
	),
	...
)
```

// получить из таблицы "pack" строчки по первичному где колонка "name" > "Alex"

```php
$filter = array(
	"name" => ">Alex"  // символ '>' в начале строки, допускаются <, <=, >, >=, =, != (по-умолчанию =)
);
$db->get('pack', $filter) 
```

Вернется:

```php
array(
	0 => array(
	    "id" => 1,
	    "name" => "Alex",
	    "date_created" => "2014-03-03 11:12:33",
	    "desired_size" => 15,
		...
	),
	...
)
```

Обновит таблицу pack, где id=3 и установит колонки в значения из $row (если какие-то колонки в $row пропущены, то данные в таблице будут установлены в NULL)

```php
$db->set('pack', $row, 3); 
```

обновит таблицу pack, где id=3 и установит только колонки в значения из $row (отсутствующие колонки в $row будут оставлены без изменений)

```php
$db->set('pack', $row, 3, false); 
```

обновит таблицу pack, где записи совпадают с условиями из $filter

```php
$db->set('pack', $row, $filter); 
```

добавит новую запись

```php
$db->set('pack', $row); 
```


Устройство фильтров

```php
$filter = array(
	"name" => ">Alex",
	"col_name_1" => "scalar"
	"col_name_2" => array("scalar_1", "scalar_2")
);
```

все колонки объединяются по AND, а внутри одной колонки по OR, т.е. в выше приведенном примере будет:

```sql
	WHERE name > "Alex" AND col_name_1 = "scalar" AND (col_name_2 = "scalar_1" OR col_name_2 = "scalar_2")
```


