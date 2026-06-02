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
    public static function HandleObjectChange($oObject): void // itop ruft diese funktion wenn ein objekt geändert oder gelöcht ist
    {
        if ($oObject === null) {
            return;
        }

        $sClass = get_class($oObject); // ob ein Server oder lnkFunctionalCIToTicket

        /*
         * Case 1:
         * An incident has been created, updated, resolved, closed or deleted.
         * In this case, all CIs linked to this incident must be recalculated.
         */
        if ($sClass === 'Incident') {
            self::UpdateLinkedCIsForIncident($oObject->GetKey()); // get the id of the incident und ruft alle verknüpften CIs die mit diese Incident verbunden sind und ruft updateLinked.. um zu berchnen
            return;
        }

        /*
         * Case 2:
         * A relation between a ticket and a configuration item has changed.
         * This happens when a CI is linked to or unlinked from a ticket.
         */
        if ($sClass === 'lnkFunctionalCIToTicket') {
            $iCIId = $oObject->Get('functionalci_id');  // holt die ID des verknüpften CI aus diesem Link
            self::UpdateCIById($iCIId);  // ruft UpdateCIById auf für diesen CI
        }
    }


    /**
     * Recalculate all CIs linked to a given incident.
     *
     * Optimisation:
     * - Single OQL query
     * - Deduplication to avoid recalculating same CI multiple times
     * - No unnecessary object loading before filtering class
     */
    private static function UpdateLinkedCIsForIncident(int $iIncidentId): void
    {
        if ($iIncidentId <= 0) {
            return;
        }

        /*
         * Retrieve all FunctionalCI links for this incident only
         * (iTop handles joins internally via lnkFunctionalCIToTicket)
         */
        $sOQL = "
        SELECT lnkFunctionalCIToTicket AS l
        WHERE l.ticket_id = :ticket_id
    ";

        $oSearch = DBObjectSearch::FromOQL($sOQL);
        $oSet = new DBObjectSet(
            $oSearch,
            array(),
            array('ticket_id' => $iIncidentId)
        );

        /*
         * Deduplication:
         * Prevent recalculating same CI multiple times
         */
        $aSeen = array();

        while ($oLink = $oSet->Fetch()) {

            $iCI = $oLink->Get('functionalci_id');

            if (empty($iCI) || isset($aSeen[$iCI])) {
                continue;
            }

            $aSeen[$iCI] = true;

            /*
             * Load CI
             */
            $oCI = MetaModel::GetObject('FunctionalCI', $iCI, false);
            if ($oCI === null) {
                continue;
            }

            /*
             * Only supported CI classes
             */
            if (!in_array(get_class($oCI), self::$aTargetClasses, true)) {
                continue;
            }

            /*
             * Recalculate
             */
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
    private static function UpdateCI($oCI): void
    {
        if ($oCI === null) {
            return;
        }

        $sClass = get_class($oCI);

        /*
         * Ignore all CI classes that are not part of the exercise scope.
         */
        if (!in_array($sClass, self::$aTargetClasses, true)) {
            return;
        }

        $iCIId = $oCI->GetKey();

        /*
         * OQL query:
         * Retrieve all open incidents linked to the current CI.
         *
         * Closed incidents are excluded with:
         * - status != resolved
         * - status != closed
         */
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

        /*
         * Count open incidents and keep the latest incident start date.
         */
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

        /*
         * Update the incident counter only if the value has changed.
         */
        if ($oCI->Get('open_incident_count') != $iCount) {
            $oCI->Set('open_incident_count', $iCount);
            $bChanged = true;
        }

        /*
         * Update the latest incident date only if the value has changed.
         */
        if ($oCI->Get('last_incident_date') != $sLastIncidentDate) {
            $oCI->Set('last_incident_date', $sLastIncidentDate);
            $bChanged = true;
        }

        /*
         * Avoid unnecessary database updates.
         */
        if ($bChanged) {
            $oCI->DBUpdate();
        }
    }
}