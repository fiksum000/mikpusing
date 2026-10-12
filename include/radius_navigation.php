<?php
function radiusNavigation($page) {
    $outside = array('sessions' => array('gear', 'Admin Settings'), 'radius-nas' => array('plus', 'Add Router — RADIUS NAS'));
    $inside = array(
        'radius-dashboard' => array('dashboard', 'Dashboard'),
        'radius-users' => array('users', 'Pelanggan PPPoE / Hotspot'),
        'radius-accounting' => array('exchange', 'Sesi & Accounting'),
        'pppoe-packages' => array('sliders', 'Paket & FUP'),
        'billing' => array('money', 'Billing / Invoice'),
        'tripay-settings' => array('credit-card', 'Payment Gateway / TriPay'),
        'fonnte' => array('whatsapp', 'WhatsApp'),
        'wa-templates' => array('comments', 'Template WhatsApp'),
        'fonnte-log' => array('list-alt', 'Log WhatsApp'),
        'billing-integrations' => array('plug', 'Integrasi & Isolir'),
        'sessions' => array('arrow-left', 'Kembali ke Admin Settings')
    );
    return in_array($page, array('sessions', 'radius-nas', 'settings', 'connect'), true) ? $outside : $inside;
}
