<?php

/**
 * iTop extension class for the incident-summary module.
 *
 * This class connects the module to iTop lifecycle hooks.
 * It also provides the optional UI bonus:
 * highlighting the open incident counter in red when its value is greater than zero.
 */
class IncidentSummaryExtension extends AbstractApplicationObjectExtension implements iBackofficeStyleExtension, iBackofficeReadyScriptExtension
{
    /**
     * Called by iTop after an object has been inserted in the database.
     *
     * The changed object is passed to the helper class in order to recalculate
     * the incident summary when needed.
     */
    public function OnDBInsert($oObject, $oChange = null)
    {
        IncidentSummaryHelper::HandleObjectChange($oObject);
    }

    /**
     * Called by iTop after an object has been updated in the database.
     *
     * This is used for example when an incident status changes to resolved or closed.
     */
    public function OnDBUpdate($oObject, $oChange = null)
    {
        IncidentSummaryHelper::HandleObjectChange($oObject);
    }

    /**
     * Called by iTop after an object has been deleted from the database.
     *
     * This allows the module to recalculate counters when an incident or a link
     * between an incident and a CI is removed.
     */
    public function OnDBDelete($oObject, $oChange = null)
    {
        IncidentSummaryHelper::HandleObjectChange($oObject);
    }

    /**
     * Back-office CSS used for the visual bonus.
     *
     * The CSS class is added by JavaScript only when open_incident_count > 0.
     */
    public function GetStyle(): string
    {
        return <<<'CSS'
.incident-summary-positive {
    color: red !important;
}

.incident-summary-positive * {
    color: red !important;
}
CSS;
    }

    /**
     * Back-office JavaScript used for the visual bonus.
     *
     * The script searches for the open_incident_count field in the displayed page.
     * If the value is greater than zero, it adds a CSS class to make the value red.
     */
    public function GetReadyScript(): string
    {
        return <<<'JS'
(function () {
    function highlightOpenIncidentCount() {
        var fields = document.querySelectorAll('[data-attribute-code="open_incident_count"]');

        fields.forEach(function (field) {
            var rawValue = field.getAttribute('data-value-raw');
            var displayedValue = field.textContent || '';
            var value = parseInt(rawValue || displayedValue, 10);

            field.classList.remove('incident-summary-positive');

            if (!isNaN(value) && value > 0) {
                field.classList.add('incident-summary-positive');
            }
        });
    }

    highlightOpenIncidentCount();

    var observer = new MutationObserver(function () {
        highlightOpenIncidentCount();
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
})();
JS;
    }
}