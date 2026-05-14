<?php

/**
 * IncidentSummaryHook
 *
 * This class listens to iTop CRUD events on Incident objects and automatically
 * updates the open_incident_count and last_incident_date fields on linked Servers.
 *
 * It implements the iApplicationObjectExtension interface which is the official
 * iTop mechanism for hooking into object lifecycle events.
 */
class IncidentSummaryHook implements iApplicationObjectExtension
{
    /**
     * Required by the interface — not used in this extension.
     */
    public function OnIsModified($oObject)
    {
        return false;
    }

    /**
     * Required by the interface — no write restrictions added.
     */
    public function OnCheckToWrite($oObject)
    {
        return array();
    }

    /**
     * Required by the interface — no delete restrictions added.
     */
    public function OnCheckToDelete($oObject)
    {
        return array();
    }

    /**
     * Triggered after an object is created in the database.
     * Updates the Server counter when a new Incident is created.
     */
    public function OnDBInsert($oObject, $oChange = null)
    {
        $this->UpdateServerCount($oObject);
    }

    /**
     * Triggered after an object is updated in the database.
     * Updates the Server counter when an Incident status changes (e.g. resolved/closed).
     */
    public function OnDBUpdate($oObject, $oChange = null)
    {
        $this->UpdateServerCount($oObject);
    }

    /**
     * Triggered before an object is deleted from the database.
     * We must retrieve linked Servers BEFORE deletion because after deletion
     * the lnkFunctionalCIToTicket link will no longer exist.
     */
    public function OnDBDelete($oObject, $oChange = null)
    {
        // Check that the object is an Incident
        if (!($oObject instanceof Incident)) {
            return;
        }

        $iTicketId = $oObject->GetKey();

        // Retrieve all Servers linked to this Incident BEFORE deletion
        $sOQL = "SELECT Server AS s JOIN lnkFunctionalCIToTicket AS l ON l.functionalci_id = s.id WHERE l.ticket_id = :ticket_id";
        $oSearch = DBObjectSearch::FromOQL($sOQL);
        $oSet = new DBObjectSet($oSearch, array(), array('ticket_id' => $iTicketId));

        // Store Server IDs to process them after deletion
        $aServerIds = array();
        while ($oServer = $oSet->Fetch()) {
            $aServerIds[] = $oServer->GetKey();
        }

        // For each linked Server, recalculate the counter excluding the deleted Incident
        foreach ($aServerIds as $iServerId) {
            $oServer = MetaModel::GetObject('Server', $iServerId, false);
            if (!is_null($oServer)) {

                // Count open Incidents excluding the one being deleted
                $sCountOQL = "SELECT Incident AS i JOIN lnkFunctionalCIToTicket AS l ON l.ticket_id = i.id WHERE l.functionalci_id = :server_id AND i.status NOT IN ('resolved', 'closed') AND i.id != :incident_id";
                $oCountSearch = DBObjectSearch::FromOQL($sCountOQL);
                $oCountSet = new DBObjectSet($oCountSearch, array(), array('server_id' => $iServerId, 'incident_id' => $iTicketId));
                $iCount = $oCountSet->Count();
                $oServer->Set('open_incident_count', $iCount);

                // Retrieve the most recent open Incident date excluding the one being deleted
                $sDateOQL = "SELECT Incident AS i JOIN lnkFunctionalCIToTicket AS l ON l.ticket_id = i.id WHERE l.functionalci_id = :server_id AND i.status NOT IN ('resolved', 'closed') AND i.id != :incident_id";
                $oDateSearch = DBObjectSearch::FromOQL($sDateOQL);
                $oDateSet = new DBObjectSet($oDateSearch, array('start_date' => false), array('server_id' => $iServerId, 'incident_id' => $iTicketId));
                $oDateSet->SetLimit(1);
                $oLastIncident = $oDateSet->Fetch();

                // Update the date set to null if no open Incidents remain
                if (!is_null($oLastIncident)) {
                    $oServer->Set('last_incident_date', $oLastIncident->Get('start_date'));
                } else {
                    $oServer->Set('last_incident_date', null);
                }

                $oServer->DBUpdate();
            }
        }
    }

    /**
     * Core business logic called by OnDBInsert and OnDBUpdate.
     * Finds all Servers linked to the Incident and updates their counters.
     */
    private function UpdateServerCount($oObject)
    {
        // Check that the object is an Incident
        if (!($oObject instanceof Incident)) {
            return;
        }

        $iTicketId = $oObject->GetKey();
        if (empty($iTicketId)) {
            return;
        }

        // Retrieve all Servers linked to this Incident via the join table
        $sOQL = "SELECT Server AS s JOIN lnkFunctionalCIToTicket AS l ON l.functionalci_id = s.id WHERE l.ticket_id = :ticket_id";
        $oSearch = DBObjectSearch::FromOQL($sOQL);
        $oSet = new DBObjectSet($oSearch, array(), array('ticket_id' => $iTicketId));

        // For each linked Server, recalculate the counter and the date
        while ($oServer = $oSet->Fetch()) {
            $iServerId = $oServer->GetKey();

            // Count open Incidents linked to this Server using OQL

            // Requête OQL pour compter les Incidents ouverts liés à ce Server via la table de liaison
            $sCountOQL = "SELECT Incident AS i JOIN lnkFunctionalCIToTicket AS l ON l.ticket_id = i.id WHERE l.functionalci_id = :server_id AND i.status NOT IN ('resolved', 'closed')";
            // Création de l'objet de recherche à partir de la requête OQL
            $oCountSearch = DBObjectSearch::FromOQL($sCountOQL);
            // Exécution de la requête avec le paramètre server_id
            $oCountSet = new DBObjectSet($oCountSearch, array(), array('server_id' => $iServerId));
            $iCount = $oCountSet->Count(); // Comptage du nombre de résultats retournés
            $oServer->Set('open_incident_count', $iCount); // Mise à jour du compteur sur l'objet Server en mémoire

            // Retrieve the most recent open Incident date sorted by start_date descending
            $sDateOQL = "SELECT Incident AS i JOIN lnkFunctionalCIToTicket AS l ON l.ticket_id = i.id WHERE l.functionalci_id = :server_id AND i.status NOT IN ('resolved', 'closed')";
            $oDateSearch = DBObjectSearch::FromOQL($sDateOQL);
            $oDateSet = new DBObjectSet($oDateSearch, array('start_date' => false), array('server_id' => $iServerId));
            $oDateSet->SetLimit(1);
            $oLastIncident = $oDateSet->Fetch();

            // Update the date set to null if no open Incidents exist
            if (!is_null($oLastIncident)) {
                $oServer->Set('last_incident_date', $oLastIncident->Get('start_date'));
            } else {
                $oServer->Set('last_incident_date', null);
            }

            // Save the updated Server to the database
            $oServer->DBUpdate();
        }
    }
}