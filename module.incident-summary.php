<?php

/**
 * Module declaration for incident-summary extension.
 *
 * This file registers the module with iTop's setup system.
 * iTop reads this file during installation to detect and load the extension.
 *
 * @module    incident-summary
 * @version   1.0.0
 * @category  business
 */
SetupWebPage::AddModule(
    __FILE__,                       // Path to this file iTop uses it to locate the module
    'incident-summary/1.0.0',       // Unique module identifier in format name/version
    array(
        // Display name shown in the iTop setup interface
        'label' => 'Incident Summary',

        // Category of the module 'business' means it extends the data model
        'category' => 'business',

        // Modules that must be installed before this one
        // itop-config-mgmt provides the Server class
        // itop-incident-mgmt-itil provides the Incident class
        'dependencies' => array(
            'itop-config-mgmt/2.4.0',
            'itop-incident-mgmt-itil/3.2.1',
        ),

        // Not mandatory — can be deselected during setup
        'mandatory' => false,

        // Visible in the setup module list
        'visible' => true,

        // PHP files containing business logic (hooks)
        // The datamodel XML is NOT listed here only PHP files
        'datamodel' => array(
            'main.incident-summary.php',
        ),

        // No SQL structure files needed
        'data.struct' => array(),

        // No sample data files needed
        'data.sample' => array(),

        // No manual setup documentation
        'doc.manual_setup' => '',

        // No additional information link
        'doc.more_information' => '',
    )
);