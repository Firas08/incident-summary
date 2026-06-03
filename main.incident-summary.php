<?php

/**
 * Business helper for the incident-summary extension.
 *
 * This class contains the calculation logic used by the module.
 * It is responsible for:
 * - detecting which CIs must be recalculated
 * - counting open incidents linked to a CI
 * - updating the calculated fields on the target CI classes
 *
 * No iTop core file is modified.
 */
class IncidentSummaryHelper
{
    /**
     * List of CI classes handled by the extension.
     *
     * The same incident summary logic is applied to:
     * - Server
     * - ApplicationSolution
     */
    private static array $aTargetClasses = array(
        'Server',
        'ApplicationSolution',
    );

    /**
     * Main entry point called by the iTop hook class.
     *
     * Depending on the object that changed, the method decides what must be recalculated:
     * - if an Incident changed, all linked CIs are recalculated
     * - if a link between a Ticket and a FunctionalCI changed, the linked CI is recalculated
     *
     * @param mixed $oObject The iTop object that has been inserted, updated or deleted.
     */
    public static function HandleObjectChange(mixed $oObject): void
    {
        if ($oObject === null) {
            return;
        }

        $sClass = get_class($oObject);

        if ($sClass === 'Incident') {
            self::UpdateLinkedCIsForIncident($oObject->GetKey());
            return;
        }

        if ($sClass === 'lnkFunctionalCIToTicket') {
            $iCIId = $oObject->Get('functionalci_id');
            self::UpdateCIById($iCIId);
        }
    }

    /**
     * Recalculate all target CIs linked to a given incident.
     *
     * iTop stores the relation between Tickets and FunctionalCIs in the
     * lnkFunctionalCIToTicket link class.
     *
     * @param int $iIncidentId Identifier of the incident to process.
     */
    private static function UpdateLinkedCIsForIncident(int $iIncidentId): void
    {
        if ($iIncidentId <= 0) {
            return;
        }

        $sOQL = "
            SELECT FunctionalCI
            JOIN lnkFunctionalCIToTicket AS l ON l.functionalci_id = FunctionalCI.id
            WHERE l.ticket_id = :ticket_id
        ";

        $oSearch = DBObjectSearch::FromOQL($sOQL);
        $oSet = new DBObjectSet($oSearch, array(), array(
            'ticket_id' => $iIncidentId,
        ));

        while ($oCI = $oSet->Fetch()) {
            self::UpdateCI($oCI);
        }
    }

    /**
     * Load a CI by its identifier and recalculate its incident summary.
     *
     * This method is mainly used when the link between a ticket and a CI changes.
     *
     * @param mixed $iCIId Identifier of the FunctionalCI.
     */
    private static function UpdateCIById(mixed $iCIId): void
    {
        if (empty($iCIId)) {
            return;
        }

        try {
            $oCI = MetaModel::GetObject('FunctionalCI', $iCIId, false);

            if ($oCI === null) {
                return;
            }

            self::UpdateCI($oCI);
        } catch (Exception $e) {
            IssueLog::Error('incident-summary: unable to load CI '.$iCIId.' - '.$e->getMessage());
        }
    }

    /**
     * Recalculate the incident summary for one CI.
     *
     * The method:
     * - checks if the CI class is supported by the extension
     * - counts open incidents linked to the CI
     * - retrieves the date of the latest open incident
     * - updates the calculated fields only if the values changed
     *
     * An incident is considered open when its status is neither:
     * - resolved
     * - closed
     *
     * @param mixed $oCI The CI object to recalculate.
     */
    private static function UpdateCI(mixed $oCI): void
    {
        if ($oCI === null) {
            return;
        }

        $sClass = get_class($oCI);

        if (!in_array($sClass, self::$aTargetClasses, true)) {
            return;
        }

        $iCIId = $oCI->GetKey();

        $sOQL = "
            SELECT Incident AS i
            JOIN lnkFunctionalCIToTicket AS l ON l.ticket_id = i.id
            WHERE l.functionalci_id = :ci_id
            AND i.status != 'resolved'
            AND i.status != 'closed'
        ";

        $oSearch = DBObjectSearch::FromOQL($sOQL);
        $oSet = new DBObjectSet($oSearch, array(), array(
            'ci_id' => $iCIId,
        ));

        $iCount = 0;
        $sLastIncidentDate = null;

        while ($oIncident = $oSet->Fetch()) {
            $iCount++;
            $sDate = $oIncident->Get('start_date');
            if (!empty($sDate)) {
                if ($sLastIncidentDate === null || $sDate > $sLastIncidentDate) {
                    $sLastIncidentDate = $sDate;
                }
            }
        }

        $bChanged = false;

        if ($oCI->Get('open_incident_count') != $iCount) {
            $oCI->Set('open_incident_count', $iCount);
            $bChanged = true;
        }

        if ($oCI->Get('last_incident_date') != $sLastIncidentDate) {
            $oCI->Set('last_incident_date', $sLastIncidentDate);
            $bChanged = true;
        }

        if ($bChanged) {
            $oCI->DBUpdate();
        }
    }
}

/**
 * iTop extension class for the incident-summary module.
 *
 * Uses the iTop 3.x EventService mechanism instead of the legacy
 * AbstractApplicationObjectExtension for object lifecycle hooks.
 * As recommended by the iTop documentation, EVENT_DB_ABOUT_TO_DELETE
 * is used instead of EVENT_DB_AFTER_DELETE.
 */
class IncidentSummaryExtension implements iBackofficeStyleExtension, iBackofficeReadyScriptExtension
{
    /**
     * Register the EventService listeners for object lifecycle events.
     * Called once during iTop startup.
     */
    public static function RegisterListeners(): void
    {
        EventService::RegisterListener(
            EVENT_DB_AFTER_WRITE,
            function (EventData $oEventData) {
                $oObject = $oEventData->Get('object');
                IncidentSummaryHelper::HandleObjectChange($oObject);
            },
            null,
            0,
            'incident-summary'
        );

        // EVENT_DB_ABOUT_TO_DELETE recommended over EVENT_DB_AFTER_DELETE
        // because the object data is still available at this point
        EventService::RegisterListener(
            EVENT_DB_ABOUT_TO_DELETE,
            function (EventData $oEventData) {
                $oObject = $oEventData->Get('object');
                IncidentSummaryHelper::HandleObjectChange($oObject);
            },
            null,
            0,
            'incident-summary'
        );
    }

    /**
     * Back-office CSS — highlights open_incident_count in red when greater than zero.
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
     * Back-office JavaScript — adds the CSS class when open_incident_count > 0.
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

// Register EventService listeners for CRUD events
IncidentSummaryExtension::RegisterListeners();

// Register UI extensions for CSS and JavaScript
MetaModel::RegisterExtension('iBackofficeStyleExtension', new IncidentSummaryExtension());
MetaModel::RegisterExtension('iBackofficeReadyScriptExtension', new IncidentSummaryExtension());