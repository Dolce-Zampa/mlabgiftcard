<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    $db = new \Mlab\GiftCard\Storage(Db::getInstance(), _DB_PREFIX_);
    foreach (['payment_confirmed_at' => 'DATETIME NULL', 'requested_cents' => 'BIGINT NULL'] as $name => $type) {
        $column = $db->row('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='
            . $db->quote(_DB_PREFIX_ . 'mlabgc_reservations') . ' AND column_name=' . $db->quote($name));
        if ($column === null) {
            $db->exec('ALTER TABLE ' . $db->table('reservations') . ' ADD `' . $name . '` ' . $type);
        }
    }
    return true;
}
