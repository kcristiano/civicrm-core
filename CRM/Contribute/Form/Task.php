<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * Class for contribute form task actions.
 * FIXME: This needs refactoring to properly inherit from CRM_Core_Form_Task and share more functions.
 */
class CRM_Contribute_Form_Task extends CRM_Core_Form_Task {

  /**
   * The array that holds all the contribution ids.
   *
   * @var array
   */
  protected $_contributionIds;

  /**
   * The array that holds all the mapping contribution and contact ids.
   *
   * @var array
   */
  protected $_contributionContactIds = [];

  /**
   * The flag to tell if there are soft credits included.
   *
   * @var bool
   */
  public $_includesSoftCredits = FALSE;

  /**
   * Build all the data structures needed to build the form.
   */
  public function preProcess() {
    self::preProcessCommon($this);
  }

  /**
   * @param \CRM_Core_Form_Task $form
   *
   * @throws \CRM_Core_Exception
   */
  public static function preProcessCommon(&$form) {
    $form->_contributionIds = [];

    $values = $form->getSearchFormValues();

    $form->_task = $values['task'] ?? NULL;

    $ids = $form->getSelectedIDs($values);
    if (!$ids) {
      $queryParams = $form->get('queryParams');
      $isTest = FALSE;
      if (is_array($queryParams)) {
        foreach ($queryParams as $fields) {
          if ($fields[0] === 'contribution_test') {
            $isTest = TRUE;
            break;
          }
        }
      }
      if (!$isTest) {
        $queryParams[] = [
          'contribution_test',
          '=',
          0,
          0,
          0,
        ];
      }
      $returnProperties = ['contribution_id' => 1];
      $sortOrder = $sortCol = NULL;
      if ($form->get(CRM_Utils_Sort::SORT_ORDER)) {
        $sortOrder = $form->get(CRM_Utils_Sort::SORT_ORDER);
        //Include sort column in select clause.
        $sortCol = trim(str_replace(['`', 'asc', 'desc'], '', $sortOrder));
        $returnProperties[$sortCol] = 1;
      }

      $form->_includesSoftCredits = CRM_Contribute_BAO_Query::isSoftCreditOptionEnabled($queryParams);
      $query = new CRM_Contact_BAO_Query($queryParams, $returnProperties, NULL, FALSE, FALSE,
        CRM_Contact_BAO_Query::MODE_CONTRIBUTE
      );
      // @todo the function CRM_Contribute_BAO_Query::isSoftCreditOptionEnabled should handle this
      // can we remove? if not why not?
      if ($form->_includesSoftCredits) {
        $contactIds = $contributionContactIds = [];
        $query->_rowCountClause = " count(civicrm_contribution.id)";
        $query->_groupByComponentClause = " GROUP BY contribution_search_scredit_combined.id, contribution_search_scredit_combined.contact_id, contribution_search_scredit_combined.scredit_id ";
      }
      else {
        $query->_distinctComponentClause = ' civicrm_contribution.id';
        $query->_groupByComponentClause = ' GROUP BY civicrm_contribution.id ';
      }
      $result = $query->searchQuery(0, 0, $sortOrder);
      while ($result->fetch()) {
        $ids[] = $result->contribution_id;
        if ($form->_includesSoftCredits) {
          $contactIds[$result->contact_id] = $result->contact_id;
          $contributionContactIds["{$result->contact_id}-{$result->contribution_id}"] = $result->contribution_id;
        }
      }
      $form->assign('totalSelectedContributions', $form->get('rowCount'));
    }

    if (!empty($ids)) {
      $form->_componentClause = ' civicrm_contribution.id IN ( ' . implode(',', $ids) . ' ) ';

      $form->assign('totalSelectedContributions', count($ids));
    }
    if (!empty($form->_includesSoftCredits) && !empty($contactIds)) {
      $form->_contactIds = $contactIds;
      $form->_contributionContactIds = $contributionContactIds;
    }

    $form->_contributionIds = $form->_componentIds = $ids;
    $form->set('contributionIds', $form->_contributionIds);
    $form->setNextUrl('contribute');
  }

  /**
   * Sets contribution Ids for unit test.
   *
   * @param array $contributionIds
   */
  public function setContributionIds($contributionIds) {
    $this->_contributionIds = $contributionIds;
  }

  /**
   * Given the contribution id, compute the contact id
   * since its used for things like send email
   */
  public function setContactIDs() {
    if (!$this->_includesSoftCredits) {
      $this->_contactIds = CRM_Core_DAO::getContactIDsFromComponent(
        $this->_contributionIds,
        'civicrm_contribution'
      );
    }
  }

  /**
   * Simple shell that derived classes can call to add buttons to
   * the form with a customized title for the main Submit
   *
   * @param string $title
   *   Title of the main button.
   * @param string $nextType
   *   Button type for the form after processing.
   * @param string $backType
   * @param bool $submitOnce
   */
  public function addDefaultButtons($title, $nextType = 'next', $backType = 'back', $submitOnce = FALSE) {
    $this->addButtons([
      [
        'type' => $nextType,
        'name' => $title,
        'isDefault' => TRUE,
      ],
      [
        'type' => $backType,
        'name' => ts('Cancel'),
      ],
    ]);
  }

}
