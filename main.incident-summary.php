<?php

/**
 * Business helper for the incident-summary extension.
 *
 * This class contains the calculation logic used by the module.
 * It is responsible for:
 * - detecting which CIs must be recalculated
 * - counting open incidents linked to a CI
 * - updating the calculated fields on the target CI classes
 * - notifying an n8n workflow when an incident changes
 *
 * No iTop core file is modified.
 */
class IncidentSummaryHelper
{
    /**
     * List of CI classes handled by the extension.
     */
    private static array $aTargetClasses = array(
        'Server',
        'ApplicationSolution',
    );

    /**
     * Main entry point called by the iTop hook class.
     *
     * @param mixed $oObject The iTop object that has been inserted, updated or deleted.
     */
    public static function HandleObjectChange($oObject): void
    {
        if ($oObject === null) {
            return;
        }

        $sClass = get_class($oObject);

        /*
         * Case 1:
         * An incident has been created, updated, resolved, closed or deleted.
         * Recalculate linked CIs and notify n8n.
         */
        if ($sClass === 'Incident') {
            $iIncidentId = (int) $oObject->GetKey();

            self::UpdateLinkedCIsForIncident($iIncidentId);
            self::NotifyN8n($iIncidentId);

            return;
        }

        /*
         * Case 2:
         * A relation between a ticket and a CI has changed.
         */
        if ($sClass === 'lnkFunctionalCIToTicket') {
            $iCIId = $oObject->Get('functionalci_id');
            self::UpdateCIById($iCIId);

            return;
        }
    }

    /**
     * Recalculate all target CIs linked to a given incident.
     *
     * @param int $iIncidentId Identifier of the incident to process.
     */
    private static function UpdateLinkedCIsForIncident(int $iIncidentId): void
    {
        if ($iIncidentId <= 0) {
            return;
        }

        $sOQL = "
            SELECT FunctionalCI AS ci
            JOIN lnkFunctionalCIToTicket AS l ON l.functionalci_id = ci.id
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
     * Recalculate open_incident_count and last_incident_date for one CI.
     *
     * @param mixed $oCI The CI object to recalculate.
     */
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

        /*
         * Retrieve all open incidents linked to the current CI.
         * An incident is considered open if its status is not resolved and not closed.
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

    /**
     * Notify the n8n workflow when an incident is created or updated.
     *
     * For test mode, the URL uses /webhook-test/.
     * When the workflow is activated in n8n, replace it with /webhook/.
     *
     * @param int $iIncidentId Identifier of the incident to analyze.
     */
    private static function NotifyN8n(int $iIncidentId): void
    {
        if ($iIncidentId <= 0) {
            return;
        }

        /*
         * Test URL for n8n.
         * Use this while clicking "Listen for test event" in n8n.
         *
         * Production URL later:
         * http://localhost:5678/webhook/itop-incident-ai
         */
        $sUrl = 'http://172.19.240.1:5678/webhook/itop-incident-ai';

        $aPayload = array(
            'incident_id' => $iIncidentId,
            'source' => 'itop',
            'event' => 'incident_changed',
            'timestamp' => date('c'),
        );

        try {
            $sJsonPayload = json_encode($aPayload);

            if ($sJsonPayload === false) {
                IssueLog::Error('incident-summary: unable to encode n8n payload');
                return;
            }

            $ch = curl_init($sUrl);

            if ($ch === false) {
                IssueLog::Error('incident-summary: unable to initialize curl for n8n');
                return;
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sJsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
            ));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);

            $sResponse = curl_exec($ch);

            if ($sResponse === false) {
                IssueLog::Error('incident-summary: n8n notification failed - '.curl_error($ch));
            }

            curl_close($ch);
        } catch (Exception $e) {
            IssueLog::Error('incident-summary: unable to notify n8n - '.$e->getMessage());
        }
    }
}