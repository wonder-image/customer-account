<?php

use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountController;

(new CustomerAccountController(AccountRoutes::panel(), AccountRoutes::auth()))
    ->handle((string) ($ROUTE_META['account_action'] ?? ''), (array) ($ROUTE_PARAMETERS ?? []));
