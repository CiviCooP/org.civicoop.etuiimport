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

    return TRUE;
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
      $status = '';
      $contactID = 0;

      // find contact by name and organization
      $contactID = self::findContactByName($dao->organization, $dao->firstName, $dao->lastName, $status);

      $updateSQL = "update tmpetui_hesamag set status = %1 where id = %2";
      $updateParams = [
        1 => [$status, 'String'],
        2 => [$id, 'Integer'],
      ];
      CRM_Core_DAO::executeQuery($updateSQL);
    }

    return TRUE;
  }

  public static function findContactByName($organization, $firstName, $lastName, &$status) {
    if ($firstName == '-' || $firstName == '--') {
      $firstName = '';
    }
    if ($lastName == '-' || $lastName == '--') {
      $lastName = '';
    }

    // try full match
    if ($organization && $firstName && $lastName) {
      $params = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'organization_name' => $organization,
        'contact_type' => 'Individual',
        'sequential' => 1,
      ];
      $result = civicrm_api3('Contact', 'get', $params);

      if ($result['count'] == 1) {
        // OK
        $status = 'OK';
        return $result['values'][0]['id'];
      }
      elseif ($result['count'] > 0) {
        // multiple matches
        $status = "multiple matches for organissation + first name + last name";
        return 0;
      }
    }

    // try match on person's name
    if ($firstName && $lastName) {
      $params = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'contact_type' => 'Individual',
        'sequential' => 1,
      ];
      $result = civicrm_api3('Contact', 'get', $params);

      if ($result['count'] == 1) {
        // OK
        $status = 'OK';
        return $result['values'][0]['id'];
      }
      elseif ($result['count'] > 0) {
        // multiple matches
        $status = "multiple matches for first name + last name";
        return 0;
      }
    }

    // try last name and organization
    if ($lastName && $organization) {
      $params = [
        'last_name' => $lastName,
        'organization_name' => $organization,
        'contact_type' => 'Individual',
        'sequential' => 1,
      ];
      $result = civicrm_api3('Contact', 'get', $params);

      if ($result['count'] == 1) {
        // OK
        $status = 'OK';
        return $result['values'][0]['id'];
      }
      elseif ($result['count'] > 0) {
        // multiple matches
        $status = "multiple matches for last name + organization";
        return 0;
      }
    }

    // try organization
    if ($organization) {
      $params = [
        'organization_name' => $organization,
        'contact_type' => 'Organization',
        'sequential' => 1,
      ];
      $result = civicrm_api3('Contact', 'get', $params);

      if ($result['count'] == 1) {
        // OK
        $status = 'OK';
        return $result['values'][0]['id'];
      }
      elseif ($result['count'] > 0) {
        // multiple matches
        $status = "multiple matches for organization";
        return 0;
      }
    }

    // not found
    $status = 'not found';
    return 0;
  }

}