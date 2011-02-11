# KohanaSage

SagePay payment gateway module for Kohana 2.3.x

## HOW TO INSTALL

1. Clone / copy in to your modules folder
2. Add to your modules list in application/config/config.php
2. Copy the sample config file in to application/config/ and edit as required

## LIST OF FUNCTIONS

* txPayment		    register a transaction
* txAuthorize	    register a transaction (authorize only)
* txRelease		    release an already registered transaction (through txAuthorize)
* txRepeat		    register a repeated transaction
* txRefund		    refund an already registered transaction
* txDirectRefund	issue a direct refund
* txManual		    register a transaction manually
* txCancel		    cancel an already registered transaction
* txAbort		    abort an already registered transaction
* txVoid		    void an already registered transation

## VERSION HISTORY

0.5 - 11 Feb 2011
    * Update by Russell Smith <russell.smith@ukd1.co.uk>
    * Ported to Kohana 2.3.x (yes, I'm aware 3.x is the current release, but this was ported for a project running 2.3.x)
    * Tidy up
    * Unit tests
    * Now tested against test api

0.4 -
    * Original version by Miguel Guerreiro for CodeIgniter
    * Only tested against SIM