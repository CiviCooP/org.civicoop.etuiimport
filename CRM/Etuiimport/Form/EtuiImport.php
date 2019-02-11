<?php

use CRM_Etuiimport_ExtensionUtil as E;

class CRM_Etuiimport_Form_EtuiImport extends CRM_Core_Form {
  private $queue;
  private $queueName = 'etuiimport';

  public function __construct() {
    // create the queue
    $this->queue = CRM_Queue_Service::singleton()->create([
      'type' => 'Sql',
      'name' => $this->queueName,
      'reset' => FALSE, //do not flush queue upon creation
    ]);

    parent::__construct();
  }

  public function buildQuickForm() {
    $action = [
      'parl' => 'Correct EU Parliament contacts without name',
      'hedamag' => 'Import HesaMag Contacts',
    ];
    $this->addRadio('action', 'Import:', $action, NULL, '<br>');

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Execute'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();

    // clear the queue
    $this->queue->deleteQueue();

    if ($values['action'] == '') {
      // do nothing
      CRM_Core_Session::setStatus('No action selected', 'Import', 'Please select an import action', 'warning');
    }
    elseif ($values['action'] == 'parl') {
      // select EU Parliament contacts without name
      $sql = "
        SELECT
          c.id
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
      ";
      $dao = CRM_Core_DAO::executeQuery($sql);
      while ($dao->fetch()) {
        $task = new CRM_Queue_Task(['CRM_Etuiimport_Importer', 'correct_eu_parl_contacts'], [$dao->id]);
        $this->queue->createItem($task);
      }
    }
    elseif ($values['action'] == 'hesamag') {
      // add id of hesamag contacts in the queue
      $sql = 'select id from tmpetui_hesamag order by id';
      $dao = CRM_Core_DAO::executeQuery($sql);
      while ($dao->fetch()) {
        $task = new CRM_Queue_Task(['CRM_Etuiimport_Importer', 'import_hesamag_subscriber'], [$dao->id]);
        $this->queue->createItem($task);
      }
    }
    else {
      CRM_Core_Session::setStatus('Action "' . $values['action'] . '" not implemented.', 'Import Error', 'warning');
    }

    if ($this->queue->numberOfItems()) {
      // run the queue
      $runner = new CRM_Queue_Runner([
        'title' => 'ETUI Import',
        'queue' => $this->queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_CONTINUE,
        'onEnd' => ['CRM_Etuiimport_Importer', 'onEnd'],
        'onEndUrl' => CRM_Utils_System::url('civicrm/etui-import', 'reset=1'),
      ]);
      $runner->runAllViaWeb();
    }

    parent::postProcess();
  }

  public function getRenderableElementNames() {
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
