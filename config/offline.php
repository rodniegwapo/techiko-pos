<?php

return [
    /**
     * Public URL to the NativePHP / Electron offline installer (e.g. .exe on S3 or /storage path).
     * Leave empty to hide the download button in the profile UI.
     */
    'installer_url' => env('OFFLINE_INSTALLER_URL', ''),
];
