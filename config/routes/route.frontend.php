<?php

use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountExtension;

// Il pannello lo registra il sito o un modulo (ecommerce): l'estensione vale prima o dopo, e una volta sola.
// Chi si appoggia alla scheda cliente la estende prima della propria estensione.
AccountRoutes::extend(new CustomerAccountExtension());
