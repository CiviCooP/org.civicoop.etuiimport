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
        h.*
        , ctry.id country_id
      FROM
        tmpetui_hesamag h
      left outer join
        civicrm_country ctry on h.country = ctry.name
      WHERE
        h.id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      $status = '';
      $contactID = 0;

      // find contact by name and organization
      $contactID = self::findContactByName($dao->organization, $dao->firstName, $dao->lastName, $status);

      if ($contactID == 0) {
        // the contact was not found, we create it
        $contactID = self::createHesaMagContact($dao->organization, $dao->prefix, $dao->firstName, $dao->lastName, $status);
      }
      
      if ($contactID > 0) {
        // add the magazine address and subscription
        self::createHesaMagAddress($contactID, $dao, $status);  
      }
      
      // update the status
      $updateSQL = "update tmpetui_hesamag set status = %1 where id = %2";
      $updateParams = [
        1 => [$status, 'String'],
        2 => [$id, 'Integer'],
      ];
      CRM_Core_DAO::executeQuery($updateSQL, $updateParams);
    }

    return TRUE;
  }

  public static function createHesaMagContact($organization, $prefix, $firstName, $lastName, &$status) {
    if ($firstName == '-' || $firstName == '--') {
      $firstName = '';
    }
    if ($lastName == '-' || $lastName == '--') {
      $lastName = '';
    }

    if ($firstName && $lastName) {
      $params = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'contact_type' => 'Individual',
        'sequential' => 1,
        'source' => 'HESAMAG import',
      ];
    }
    elseif ($organization && strpos($organization, $lastName) == FALSE) {
      $params = [
        'organization_name' => $lastName,
        'contact_type' => 'Organization',
        'sequential' => 1,
        'source' => 'HESAMAG import',
      ];
    }
    else {
      $status = 'Cannot create contact';
      return -1;
    }

    $result = civicrm_api3('Contact', 'create', $params);
    $status = 'created';
    return $result['id'];
  }

  public static function createHesaMagAddress($contactID, $dao, &$status) {
    // get some info about this contact
    $sql = "
      select
        c.contact_type
        , a.id address_id
        , m_en.id mag_en_id
        , m_fr.id mag_fr_id 
      from
        civicrm_contact c
      left outer join
        civicrm_address a on a.contact_id = c.id and a.location_type_id = 8
      left outer join
        civicrm_membership m_en on m_en.contact_id = m_en.id and m_en.membership_type_id = 1 
      left outer join
        civicrm_membership m_fr on m_fr.contact_id = m_fr.id and m_fr.membership_type_id = 2 
      where
        c.id = $contactID 
    ";
    $contact = CRM_Core_DAO::executeQuery($sql);
    if (!$contact->fetch()) {
      $status = "Cannot find contact with ID = $contactID";
      return;
    }

    /*

     */
    // create the address
    $addressParams = [
      'contact_id' => $contactID,
      'location_type_id' => 8,
      'street_address' => $dao->street_address,
      'city' => $dao->city,
      'postal_code' => $dao->postal_code,
    ];

    if ($dao->country_id) {
      $addressParams['country_id'] = $dao->country_id;
    }
    elseif ($dao->country) {
      // this is a country that was not found in the country table, manually correct is
      $addressParams['country_id'] = self::getCountryID($dao->country);
    }
    else {
      // make belgium the default country
      $addressParams['country_id'] = 1020;
    }

    if ($contact->address_id) {
      // update existing address
      $addressParams['id'] = $contact->address_id;
    }

    // FORMAT THE ADDRESS

    // check for a person name
    if ($dao->first_name || $dao->last_Name) {
      $name = $dao->first_name . ' ' . $dao->last_Name;
    }
      // check if it's a real organization (ie. not a fake from Synergy)
    if ($dao->organization && strpos($dao->organization, $dao->last_Name) == FALSE) {
      $addressParams['supplemental_address_1'] = $dao->organization;

      if ($name && $dao->department) {
        $addressParams['supplemental_address_2'] = $dao->department;
        $addressParams['supplemental_address_3'] = 'Attn. ' . $name;
      }
      elseif ($dao->department) {
        $addressParams['supplemental_address_2'] = $dao->department;
      }
      elseif ($name) {
        $addressParams['supplemental_address_2'] = 'Attn. ' . $name;
      }
    }
    else {
        // person
      if ($name && $dao->department) {
        $addressParams['supplemental_address_1'] = $dao->department;
        $addressParams['supplemental_address_2'] = 'Attn. ' . $name;
      }
      elseif ($dao->department) {
        $addressParams['supplemental_address_1'] = $dao->department;
      }
      elseif ($name) {
        $addressParams['supplemental_address_1'] = $name;
      }
    }

    civicrm_api3('Address', 'create', $addressParams);

    // create the subscription
    $magParams = [
      'join_date' => '2018-04-01',
      'start_date' => '2018-04-01',
      'contact_id' => $contactID,
      'source' => 'HesaMag Import',
      'status_id' => 2,
    ];

    if ($dao->magazine_lang == 'EN') {
      $magParams['membership_type_id'] = 1;

      // udpate or create?
      if ($contact->mag_en_id) {
        $magParams['id'] = $contact->mag_en_id;
      }
    }
    elseif ($dao->magazine_lang == 'FR') {
      $magParams['membership_type_id'] = 2;

      // udpate or create?
      if ($contact->mag_fr_id) {
        $magParams['id'] = $contact->mag_fr_id;
      }
    }
    else {
      $status = 'Invalid magazine language';
      return;
    }

  }

  public static function getCountryID($country) {
    if ($country == 'United States of America') {
      return 1228;
    }
    elseif ($country == 'Azerbaidjan') {
      return 1015;
    }
    elseif ($country == 'Macedonia') {
      return 1128;
    }
    elseif ($country == 'South-Korea') {
      return 1115;
    }
    elseif ($country == 'The Democratic Republic of the Congo') {
      return 1050;
    }
    else {
      return 1020; // belgium
    }
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
        return -1;
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
        return -1;
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
        return -1;
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
        return -1;
      }
    }

    // not found
    $status = 'not found';
    return 0;
  }

}