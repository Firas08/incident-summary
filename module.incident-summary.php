<?php

/**
 * Module declaration file for the incident-summary extension.
 *
 * This file registers the extension in the iTop setup wizard.
 * The module is installed as an independent extension and does not modify
 * any iTop core file.
 */

SetupWebPage::AddModule(
    __FILE__,
    'incident-summary/1.0.0',
    array(
        /*
         * Display name of the module in the iTop setup wizard.
         */
        'label' => 'Incident Summary',

        /*
         * Module category displayed during setup.
         */
        'category' => 'business',

        /*
         * Required iTop modules.
         */
        'dependencies' => array(
            'itop-config-mgmt/3.2.0',
            'itop-incident-mgmt-itil/3.2.0',
        ),

        /*
         * The module is optional and visible in the setup wizard.
         */
        'mandatory' => false,
        'visible' => true,

        /*
         * PHP files loaded by iTop for this module.
         */
        'datamodel' => array(
            'model.incident-summary.php',
            'main.incident-summary.php',
            'dictionaries/de.dict.incident-summary.php',
            'dictionaries/en.dict.incident-summary.php',
            'dictionaries/fr.dict.incident-summary.php',
        ),

        /*
         * No additional webservice, data structure or sample data is required.
         */
        'webservice' => array(),
        'data.struct' => array(),
        'data.sample' => array(),

        /*
         * Documentation links
         */
        'doc.manual_setup' => '',
        'doc.more_information' => '',

        /*
         * No configurable settings are required.
         */
        'settings' => array(),
    )
);