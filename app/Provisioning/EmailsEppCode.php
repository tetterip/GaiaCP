<?php

namespace App\Provisioning;

/**
 * A DomainRegistrar that cannot hand the EPP code back: its eppCode() asks the registrar to email the code to the
 * domain's registrant and returns ''. DomainLifecycle::eppCode() then returns null and the screens say it was emailed.
 */
interface EmailsEppCode {}
