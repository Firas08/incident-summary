<?php
SetupWebPage::AddModule(
    __FILE__,
    'incident-summary/1.0.0',
    array(
        'label' => 'Incident Summary',
        'category' => 'business',
        'dependencies' => array('itop-tickets/3.2.0'),
        'mandatory' => false,
        'visible' => true,
        'datamodel' => array(
            'main.incident-summary.php',
        ),
    )
);