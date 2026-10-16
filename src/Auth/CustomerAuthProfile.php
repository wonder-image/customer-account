<?php

namespace Wonder\Plugin\CustomerAccount\Auth;

use Wonder\Auth\Frontend\AuthProfile;
use Wonder\Plugin\CustomerAccount\Auth\Concerns\HasSignupFields;

/** Il profilo del core con i campi di registrazione configurabili del modulo (vedi HasSignupFields). */
class CustomerAuthProfile extends AuthProfile
{
    use HasSignupFields;
}
