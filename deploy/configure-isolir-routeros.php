<?php
/**
 * Disabled in Full RADIUS mode.
 *
 * This legacy helper modified MikroTik firewall rules over RouterOS API.
 * Configure isolation redirect rules manually from Admin Settings -> Billing
 * Integrations / Add Router — RADIUS NAS, then paste the generated commands
 * into the MikroTik RouterOS terminal. This script intentionally does not
 * connect to routers or make network changes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
fwrite(STDERR,
    "DISABLED: RouterOS API provisioning is not used in Full RADIUS mode.\n" .
    "Open Mikhmon Admin Settings -> Billing Integrations or Add Router — RADIUS NAS,\n" .
    "review the generated RouterOS v7 commands, and apply them manually in the router terminal.\n"
);
exit(2);
?>
