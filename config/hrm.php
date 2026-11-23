<?php

return [

    'devices' => [
        // Serial numbers of attendance machines allowed to call /iclock.
        // Comma-separated in HRM_DEVICE_SERIALS. Replaced by the devices table in Phase 3.
        'allowed_serials' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('HRM_DEVICE_SERIALS', ''))),
            fn (string $serial) => $serial !== '',
        )),
    ],

];
