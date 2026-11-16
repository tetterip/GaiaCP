<?php

namespace App\Provisioning;

/**
 * A DomainRegistrar whose API cannot give the EPP code at all (Namecheap): its eppCode() throws with where to find it.
 * The client area hides "Get EPP code" for its domains (DomainLifecycle::givesEppCode()); the admin's button still
 * explains where to get it.
 */
interface GivesNoEppCode {}
