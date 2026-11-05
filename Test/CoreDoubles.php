<?php
// Public core contracts only; real MySQL persistence is supplied by the fixture.
define('_DB_PREFIX_', 'gc_core_test_');
class Db { public static GiftCardTestDb $adapter; public static function getInstance() { return self::$adapter; } }
class Configuration
{
    public static array $values = [];
    public static function getGlobalValue($key) { return self::$values[$key] ?? false; }
    public static function updateGlobalValue($key, $value) { self::$values[$key] = $value; return true; }
    public static function get($key, $lang = null, $group = null, $shop = null) { return self::$values[$key] ?? false; }
}
class Validate { public static function isLoadedObject($object) { return (int) $object->id > 0; } }
class Customer
{
    public static array $records = [];
    public $id = 0, $id_shop = 1, $deleted = false, $email = 'buyer@example.test', $firstname = 'Buyer', $lastname = 'Test';
    public function __construct($id) { foreach (self::$records[$id] ?? [] as $key => $value) { $this->$key = $value; } }
}
class Currency
{
    public static array $records = [];
    public $id = 0, $iso_code = 'EUR', $active = true, $deleted = false, $precision = 2;
    public function __construct($id) { foreach (self::$records[$id] ?? [] as $key => $value) { $this->$key = $value; } }
    public static function getIdByIsoCode($iso, $shop = 0)
    {
        foreach (self::$records as $id => $record) { if ($record['iso_code'] === $iso) { return $id; } }
        return 0;
    }
}
class Cart
{
    public const BOTH = 3;
    public static array $records = [];
    public $id = 0, $id_shop = 1, $id_customer = 42, $id_currency = 1, $products = [], $total = 100.0;
    public function __construct($id) { foreach (self::$records[$id] ?? [] as $key => $value) { $this->$key = $value; } }
    public function getProducts() { return $this->products; }
    public function getOrderTotal($taxes, $type) { return $this->total; }
}
class Order
{
    public static array $records = [];
    public $id = 0, $id_shop = 1, $id_cart = 11, $id_customer = 42, $id_currency = 1,
        $current_state = 2, $total_paid_tax_incl = 100.0, $products = [];
    public function __construct($id) { foreach (self::$records[$id] ?? [] as $key => $value) { $this->$key = $value; } }
    public function getProducts() { return $this->products; }
}
class OrderState
{
    public $id = 0, $paid = false;
    public function __construct($id) { $this->id = $id; $this->paid = (int) $id === 2; }
}
class PrestaShopLogger { public static array $messages = []; public static function addLog($message, $level) { self::$messages[] = $message; } }
class Mail
{
    public static bool $fail = false;
    public static array $messages = [];
    public static function Send(...$arguments) { self::$messages[] = $arguments; return !self::$fail; }
}
class Module
{
    protected $tabs = [];
    public $active = true;
    public $name, $tab, $version, $author, $need_instance, $bootstrap, $controllers, $ps_versions_compliancy,
        $displayName, $description, $confirmUninstall, $context, $_errors = [], $hooks = [];
    public function __construct() { $this->context = (object) ['shop' => (object) ['id' => 1]]; }
    public function l($value) { return $value; }
    public function install() { return true; }
    public function uninstall() { return true; }
    public function registerHook($hooks) { $this->hooks = $hooks; return true; }
}
class Language
{
    public static function getLanguages($active = true) { return [['id_lang' => 1], ['id_lang' => 2]]; }
}
class Tab
{
    public static array $records = [];
    public $id = 0, $class_name, $module, $id_parent, $active, $enabled, $icon, $name = [];
    public function __construct($id = null)
    {
        foreach (self::$records[$id] ?? [] as $key => $value) { $this->$key = $value; }
    }
    public static function getIdFromClassName($name)
    {
        if ($name === 'SELL') { return 1; }
        foreach (self::$records as $id => $record) { if ($record['class_name'] === $name) { return $id; } }
        return 0;
    }
    public function save()
    {
        $this->id = $this->id ?: count(self::$records) + 2;
        self::$records[$this->id] = get_object_vars($this);
        return true;
    }
}
