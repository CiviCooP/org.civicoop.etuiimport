<?php

class CRM_Etuiimport_Importer {
  public static function onEnd(CRM_Queue_TaskContext $ctx) {
    CRM_Core_Session::setStatus('Done.', 'Queue', 'success');
  }

  public static function correct_eu_parl_contacts(CRM_Queue_TaskContext $ctx, $id) {
    // select the subscriber
    $sql = "
      SELECT
        e.email
        , replace(e.email, '@europarl.europa.eu', '') fn_dot_ln
      FROM
        civicrm_contact c
      inner join
        civicrm_email e on e.contact_id = c.id
      where
        c.contact_type = 'Individual'
      and
        c.is_deleted = 0
      and
        ifnull(first_name, '') = ''
      and
        ifnull(last_name, '') = ''
      and
        email like '%@europarl.europa.eu'      
      and
        c.id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      $splitName = explode('.', $dao->fn_dot_ln);
      if (count($splitName) != 2) {
        // skip
        watchdog('alain', 'Manual cleanup: id = ' . $dao->id);
      }
      else {
        $params = [
          'id' => $id,
          'first_name' => ucfirst($splitName[0]),
          'last_name' => ucfirst($splitName[1]),
          'employer_id' => 13240,
        ];
        civicrm_api3('Contact', 'create', $params);
      }
    }
  }

  public static function import_hesamag_subscriber(CRM_Queue_TaskContext $ctx, $id) {
    // select the subscriber
    $sql = "
      SELECT
        *
      FROM
        tmpetui_hesamag
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if this contact exists in civi
      $params = [
        'first_name' => $dao->first_name,
        'last_name' => $dao->last_name,
      ];
    }

    return TRUE;
  }
}