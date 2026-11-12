<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module)
{
    // This release adds bundled PDF templates; no financial data is changed.
    return true;
}
