<?php

namespace mii\db;


use mii\util\Arr;

class ORM
{

    /**
     * @var string database table name
     */
    protected static $table;

    /**
     * @var mixed
     */
    protected $_order_by = false;

    /**
     * @var array The database fields
     */
    protected array $attributes = [];

    /**
     * @var array Auto-serialize and unserialize columns on get/set
     */
    protected $_serialize_fields;


    protected $_serialize_cache = [];


    /**
     * @var  array  Data that's changed since the object was loaded
     */
    protected $_changed = [];


    protected $_exclude_fields = [];

    /**
     * @var boolean Is this model loaded from DB
     */
    public $__loaded;


    /**
     * Create a new ORM model instance
     *
     * @param array $values
     * @param mixed
     * @return void
     */
    public function __construct(array $values = null, bool $loaded = false)
    {
        if (!\is_null($values)) {
            foreach ($values as $key => $value) {
                $this->$key = $value;
            }
        }
        $this->__loaded = $loaded;
    }

    /**
     *
     * @return Query
     */
    public static function query()
    {
        return (new static)
            ->raw_query()
            ->table(static::$table)
            ->as_object(static::class, [[], true]);
    }

    /**
     * @param array $value
     * @return array
     */
    public static function all(array $value = null): array
    {
        if (\is_null($value))
            return static::find()->all();

        assert(!is_array($value[0]), "This method accepts only array of int/string's");

        return (new static)
            ->select_query()
            ->where('id', 'IN', $value)
            ->all();
    }


    /**
     * @param array|null $conditions
     * @return Query
     */
    public static function find(array $conditions = null) : Query
    {
        if(\is_null($conditions))
            return (new static)->select_query();

        if(count($conditions) === 3 && \is_string($conditions[1])) {
            $conditions = [$conditions];
        }

        return (new static)
            ->select_query()
            ->where($conditions);
    }

    /**
     * @param int $value
     * @param bool $find_or_fail
     * @return $this|null
     * @throws ModelNotFoundException
     */
    public static function one(int $value, bool $find_or_fail = false)
    {
        $result = (new static)->select_query(false)->where('id', '=', $value)->one();

        if ($find_or_fail && $result === null)
            throw new ModelNotFoundException;

        return $result;
    }

    /**
     * @param bool $with_order
     * @return Query
     */
    public function select_query($with_order = true, Query $query = null): Query
    {
        if ($query === null)
            $query = new Query;

        $query->select()->from($this->get_table())->as_object(static::class, [null, true]);

        if ($this->_order_by AND $with_order) {
            foreach ($this->_order_by as $column => $direction) {
                $query->order_by($column, $direction);
            }
        }

        return $query;
    }

    public function raw_query(): Query
    {
        return new Query;
    }

    /**
     * Returns an array of the columns in this object.
     *
     * @return array
     */
    public function fields(): array
    {
        $fields = [];

        $table = $this->get_table();

        foreach ($this->attributes as $key => $value) {
            if (!\in_array($key, $this->_exclude_fields)) {
                $fields[] = "`$table`.`$key`"; // TODO: support for table prefixes
            }
        }

        return $fields;
    }

    /**
     * Gets the table name for this object
     *
     * @return string
     */
    public function get_table(): string
    {
        return static::$table;
    }

    /**
     * Returns an associative array, where the keys of the array is set to $key
     * column of each row, and the value is set to the $display column.
     *
     * @param string $key the key to use for the array
     * @param string $display the value to use for the display
     * @param string $first first value
     *
     * @return Result
     */
    public static function select_list($key, $display, $first = NULL)
    {
        $class = new static();

        $query = $class->raw_query()
            ->select([static::$table . '.' . $key, static::$table . '.' . $display])
            ->from($class->get_table())
            ->as_array();

        if ($class->_order_by) {
            foreach ($class->_order_by as $column => $direction) {
                $query->order_by($column, $direction);
            }
        }

        return $query->get()->to_list($key, $display, $first);
    }


    public function __set($key, $value)
    {
        if(\is_null($this->__loaded)) {
            $this->attributes[$key] = $value;
            return;
        }


        if ($this->_serialize_fields !== null && \in_array($key, $this->_serialize_fields)) {
            $this->_serialize_cache[$key] = $value;
            return;
        }

        if ($this->__loaded === true) {
            if ($value !== $this->attributes[$key]) {
                $this->_changed[$key] = true;
            }
        }
        $this->attributes[$key] = $value;
    }

    public function __get($key)
    {
        return $this->attributes[$key];


        throw new ORMException('Field ' . $key . ' does not exist in ' . \get_class($this) . '!');
    }

    public function get(string $key)
    {
        if (isset($this->_data[$key]) OR \array_key_exists($key, $this->_data)) {

            return ($this->_serialize_fields !== null && \in_array($key, $this->_serialize_fields, true))
                ? $this->_unserialize_value($key)
                : $this->_data[$key];
        }

        throw new ORMException('Field ' . $key . ' does not exist in ' . \get_class($this) . '!');
    }

    public function set($values, $value = NULL): ORM
    {
        if (\is_object($values) AND $values instanceof \mii\web\Form) {

            $values = $values->changed_fields();

        } elseif (!\is_array($values)) {
            $values = [$values => $value];
        }

        foreach ($values as $key => $value) {
            if (\array_key_exists($key, $this->_data)) {

                if ($this->_serialize_fields !== null && \in_array($key, $this->_serialize_fields)) {
                    $this->_serialize_cache[$key] = $value;
                } else {
                    if ($value !== $this->_data[$key]) {
                        $this->_changed[$key] = true;
                    }
                    $this->attributes[$key] = $value;
                }

            } else {
                $this->_unmapped[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * Magic isset method to test _data
     *
     * @param string $name the property to test
     *
     * @return bool
     */
    public function __isset($key)
    {
        return \array_key_exists($key, $this->attributes);
    }

    /**
     * Gets an array version of the model
     *
     * @return array
     */
    public function to_array(array $properties = []): array
    {
        if (empty($properties)) {
            return $this->_data;
        }

        return Arr::to_array($this, $properties);
    }

    /**
     * Checks if the field (or any) was changed
     *
     * @param string|array $field_name
     * @return bool
     */

    public function changed($field_name = null): bool
    {
        // For not loaded models there is no way to detect changes.
        if (!$this->loaded())
            return true;

        if ($field_name === null) {
            return \count($this->_changed) > 0;
        }

        if (\is_array($field_name)) {
            return \count(array_intersect($field_name, array_keys($this->_changed)));
        }

        return isset($this->_changed[$field_name]);
    }

    /**
     * Determine if this model is loaded.
     *
     * @return bool
     */
    public function loaded($value = null)
    {
        if ($value !== null)
            $this->__loaded = (bool)$value;

        return (bool)$this->__loaded;
    }

    /**
     * Saves the model to your database.
     *
     * @param mixed $validation a manual validation object to combine the model properties with
     *
     * @return int Affected rows
     */
    public function update()
    {
        if ($this->_serialize_fields !== null && !empty($this->_serialize_fields))
            $this->_invalidate_serialize_cache();

        if (!(bool)$this->_changed)
            return 0;

        if ($this->on_update() === false)
            return 0;

        $this->on_change();

        $data = array_intersect_key($this->_data, $this->_changed);

        $this->raw_query()
            ->update($this->get_table())
            ->set($data)
            ->where('id', '=', $this->_data['id'])
            ->execute();

        $this->on_after_update();
        $this->on_after_change();

        $this->_changed = [];

        return \Mii::$app->db->affected_rows();
    }

    protected function on_create()
    {
        return true;
    }

    protected function on_update()
    {
        return true;
    }

    protected function on_change()
    {
    }

    protected function on_after_create(): void
    {
    }

    protected function on_after_update(): void
    {
    }

    protected function on_after_change(): void
    {
    }

    protected function on_after_delete(): void
    {
    }

    /**
     * Saves the model to your database. It will do a
     * database INSERT and assign the inserted row id to $data['id'].
     *
     * @return int Inserted row id
     */
    public function create()
    {
        if ($this->_serialize_fields !== null && !empty($this->_serialize_fields))
            $this->_invalidate_serialize_cache();

        if ($this->on_create() === false) {
            return 0;
        }

        $this->on_change();

        $columns = array_keys($this->attributes);
        $this->raw_query()
            ->insert($this->get_table())
            ->columns($columns)
            ->values($this->attributes)
            ->execute();

        $this->__loaded = true;

        $this->attributes['id'] = \Mii::$app->db->inserted_id();

        $this->on_after_create();
        $this->on_after_change();

        $this->_changed = [];

        return $this->attributes['id'];
    }


    /**
     * Deletes the current object's associated database row.
     * The object will still contain valid data until it is destroyed.
     *
     */
    public function delete(): void
    {
        if ($this->loaded()) {
            $this->__loaded = false;

            $this->raw_query()
                ->delete($this->get_table())
                ->where('id', '=', $this->attributes['id'])
                ->execute();

            $this->on_after_delete();

            return;
        }

        throw new ORMException('Cannot delete a non-loaded model ' . \get_class($this) . '!');
    }

    protected function _invalidate_serialize_cache(): void
    {
        if ($this->_serialize_fields === null || empty($this->_serialize_cache))
            return;

        foreach ($this->_serialize_fields as $key) {

            $value = isset($this->_serialize_cache[$key])
                ? $this->_serialize_value($this->_serialize_cache[$key])
                : $this->_serialize_value($this->attributes[$key]);

            if ($value !== $this->attributes[$key]) {
                $this->attributes[$key] = $value;

                if ($this->__loaded)
                    $this->_changed[$key] = true;
            }

        }
    }

    protected function _serialize_value($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    protected function _unserialize_value($key)
    {
        if (!\array_key_exists($key, $this->_serialize_cache)) {
            assert(is_string($this->attributes[$key]), 'Unserialized field must have a string value');
            $this->_serialize_cache[$key] = json_decode($this->attributes[$key], TRUE);
        }
        return $this->_serialize_cache[$key];
    }
}
