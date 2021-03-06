# DB, Database

_документация устарела_

Основной класс работающий с mysql – Database.

Так код простого запроса к базе данных будет выглядеть примерно так:
```php
Mii::$app->db->query(Database::SELECT, 'SELECT * FROM users WHERE id = ' . $id);
```

Но так делать не надо. Есть обертка DB, позволяющая удобно делать прямые запросы:

```php
$result = DB::select('SELECT * FROM users WHERE id = :id', [':id' => 1]);

$result = DB::update('UPDATE users SET name = :name WHERE id = :id', [':name' => 'John', ':id' => 1]);
```

Любой SELECT запрос всегда возвращает объект типа /mii/db/Result, INSERT вернет last_inserted_id, 
остальные виды запросов — количество затронутых строк. Подробнее про Result будет чуть позже. 

Для еще более удобной работы, есть традиционный query builder:

```php
(new Query)->select()->from('users')->where('username', '=', 'john')->get();

(new Query)->select(['name', 'surname'])->from('users')->where('id', '=', '1')->one();

(new Query)->delete('users')->where('username', '=', 'john')->execute();

(new Query)->update('users')->set(['username', '=', 'john'])->execute();

(new Query)->insert('users')->values(['john', 'doe'])->execute();

(new Query)->insert('users', ['name' => 'john', 'surname' => 'doe')->execute();

```

Основной способ выполнения запроса для Query Builder это метод get. Он просто выполняет запрос и возвращает /mii/db/Result

Но есть ряд специальных способов выполнения:

one() – добавляет в запрос limit 1 и возвращает первую строку ответа или null. 

all() – заменяет цепочку ->execute()->all();

count() — добавляет в select конструкцию COUNT(), выполняет запрос, возвращает количество результатов (int) 

```php
$count = (new Query)->from('users')->where('name', 'like', '%oh')->count();
```
будет аналогично:

```php
$result = (new Query)->select(DB::expr('COUNT(*)'))->from('users')->where('name', 'like', '%oh')->get();
$count = $result[0]['COUNT(*)'];
```

*(Кстати, count возвращает исходное значение select в оригинальный запрос, так что его можно безболезненно использовать вместе 
со сложным запросом.)*

Еще один способ повлиять на форму результатов, это методы as_array/as_object и index_by

Вызов as_array указывает QueryBuilder'у, что результат должен быть массивом. as_object, соответственно, позволяет получить
результаты в виде объектов.
 
index_by позволяет сформировать ассоциативный массив по нужному ключу, например:

```
$result = (new Query)
            ->select()
            ->from('users')
            ->index_by('age')
            ->all();
```
вернет массив, вида:
```
[
    17 => ['name' => 'John', 'age' => 17, ...],
    21 => ['name' => 'Jane', 'age' => 21', ...]
    ...
]
```
Если речь идет об использовании Query Builder совместно с ORM, то в результате мы получим ассоциативный массив, где каждый 
элемент является объектом модели (если только не вызвать с as_array).


### Result

Результатом выполнения любого select запроса к базе (кроме, очевидно, one|all|count) будет (внезапно) экземпляр класса /mii/db/Result.

По сути это достаточно тонкая обертка вокруг mysqli_result, реализующая интерфейсы Countable, Iterator, SeekableIterator, ArrayAccess
и еще несколько удобностей. Проще говоря, с Result можно работать как с массивом (разве что нельзя записывать в него значения 
и не получится вывести его содержимое с помощью print_r).

Удобности:

`all()` – если мы получаем объекты, то сформирует массив объектов. 
Если мы получаем массив, то возвращает полный массив результатов,
используя mysqli метод fetch_all, что в некоторых случаях повышает производительность. Стоит использовать этот метод в тех случаях,
когда вам нужен полный массив результатов для его последующей сложной обработки. Если же единственное предполагаемое использование массива это 
простое прохождение его в цикле foreach (к примеру, для вывода), то смысла в этом методе мало.

`column($name, $default = null)` — вернет значение конкретного поля текущей строки результата.

`to_list($key, $display, $first = null)` — специализированный метод. Обычно нужен при формировании select input в формах. Возвращает
массив, где ключами будут колонки результата с именем $key, а значениями колонки с именем $display. В $first можно передать то,
что станет первым значением списка (или строка или ассоциативный массив).

`to_array()` — конвертирует и отдает результаты в виде массива. Если мы работаем с объектами, то они
так же будут сконвертированы в массив.




