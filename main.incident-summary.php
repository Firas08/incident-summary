<?php

class IncidentSummaryHelper
{
    private static array $aTargetClasses = array(
        'Server',
        'ApplicationSolution',
    );

    public static function HandleObjectChange($oObject): void
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

    private static function UpdateCIById($iCIId): void
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

    private static function UpdateCI($oCI): void
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

class IncidentSummaryExtension implements iBackofficeStyleExtension, iBackofficeReadyScriptExtension
{
    public static function RegisterListeners(): void
    {
        EventService::RegisterListener(
            EVENT_DB_AFTER_WRITE,
            function (EventData $oEventData) {
                $oObject = $oEventData->Get('object');
                IncidentSummaryHelper::HandleObjectChange($oObject);
            }
        );

        // EVENT_DB_ABOUT_TO_DELETE recommended over EVENT_DB_AFTER_DELETE
        // because the object data is still available at this point
        EventService::RegisterListener(
            EVENT_DB_ABOUT_TO_DELETE,
            function (EventData $oEventData) {
                $oObject = $oEventData->Get('object');
                IncidentSummaryHelper::HandleObjectChange($oObject);
            }
        );
    }

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