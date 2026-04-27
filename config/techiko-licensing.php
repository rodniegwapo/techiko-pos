<?php

return [
    /**
     * When true, routes using the `license.domain` middleware require an active license
     * for the resolved Domain (see EnsureDomainLicenseValid).
     */
    'enforce_domain_license' => (bool) env('LICENSING_ENFORCE_DOMAINS', false),
];
