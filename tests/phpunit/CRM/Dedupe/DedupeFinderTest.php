<?php

/**
 * Class CRM_Dedupe_DedupeFinderTest
 * @group headless
 */
class CRM_Dedupe_DedupeFinderTest extends CiviUnitTestCase {

  use CRMTraits_Custom_CustomDataTrait;
  /**
   * IDs of created contacts.
   *
   * @var array
   */
  protected $contactIDs = [];

  /**
   * ID of the group holding the contacts.
   *
   * @var int
   */
  protected $groupID;

  /**
   * Clean up after the test.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown() {

    foreach ($this->contactIDs as $contactId) {
      $this->contactDelete($contactId);
    }
    if ($this->groupID) {
      $this->callAPISuccess('group', 'delete', ['id' => $this->groupID]);
    }
    $this->quickCleanup(['civicrm_contact'], TRUE);
    CRM_Core_DAO::executeQuery("DELETE r FROM civicrm_dedupe_rule_group rg INNER JOIN civicrm_dedupe_rule r ON rg.id = r.dedupe_rule_group_id WHERE rg.is_reserved = 0 AND used = 'General'");
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_dedupe_rule_group WHERE is_reserved = 0 AND used = 'General'");

    parent::tearDown();
  }

  /**
   * Test the unsupervised dedupe rule against a group.
   *
   * @throws \Exception
   */
  public function testUnsupervisedDupes() {
    // make dupe checks based on following contact sets:
    // FIRST - LAST - EMAIL
    // ---------------------------------
    // robin  - hood - robin@example.com
    // robin  - hood - hood@example.com
    // robin  - dale - robin@example.com
    // little - dale - dale@example.com
    // will   - dale - dale@example.com
    // will   - dale - will@example.com
    // will   - dale - will@example.com
    $this->setupForGroupDedupe();

    $ruleGroup = $this->callAPISuccessGetSingle('RuleGroup', ['is_reserved' => 1, 'contact_type' => 'Individual', 'used' => 'Unsupervised']);

    $foundDupes = CRM_Dedupe_Finder::dupesInGroup($ruleGroup['id'], $this->groupID);
    $this->assertEquals(count($foundDupes), 3, 'Check Individual-Fuzzy dupe rule for dupesInGroup().');
  }

  /**
   * Test duplicate contact retrieval with 2 email fields.
   *
   * @throws \CRM_Core_Exception
   */
  public function testUnsupervisedWithTwoEmailFields() {
    $this->setupForGroupDedupe();
    $emails = [
      ['hood@example.com', ''],
      ['', 'hood@example.com'],
    ];
    for ($i = 0; $i < 2; $i++) {
      $fields = [
        'first_name' => 'robin',
        'last_name' => 'hood',
        'email-1' => $emails[$i][0],
        'email-2' => $emails[$i][1],
      ];
      $dedupeParams = CRM_Dedupe_Finder::formatParams($fields, 'Individual');
      $dedupeResults = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual');
      $this->assertEquals(count($dedupeResults), 1);
    }
  }

  /**
   * Test that a rule set to is_reserved = 0 works.
   *
   * There is a different search used dependent on this variable.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCustomRule() {
    $this->setupForGroupDedupe();

    $ruleGroup = $this->createRuleGroup();
    foreach (['birth_date', 'first_name', 'last_name'] as $field) {
      $rules[$field] = $this->callAPISuccess('Rule', 'create', [
        'dedupe_rule_group_id' => $ruleGroup['id'],
        'rule_table' => 'civicrm_contact',
        'rule_weight' => 4,
        'rule_field' => $field,
      ]);
    }
    $foundDupes = CRM_Dedupe_Finder::dupesInGroup($ruleGroup['id'], $this->groupID);
    $this->assertEquals(count($foundDupes), 4);
    CRM_Dedupe_Finder::dupes($ruleGroup['id']);

  }

  /**
   * Test that we do not get a fatal error when our rule group is a custom date field.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCustomRuleCustomDateField() {

    $ruleGroup = $this->createRuleGroup();
    $this->createCustomGroupWithFieldOfType([], 'date');
    $this->callAPISuccess('Rule', 'create', [
      'dedupe_rule_group_id' => $ruleGroup['id'],
      'rule_table' => $this->getCustomGroupTable(),
      'rule_weight' => 4,
      'rule_field' => $this->getCustomFieldColumnName('date'),
    ]);

    CRM_Dedupe_Finder::dupes($ruleGroup['id']);
  }

  /**
   * Test a custom rule with a non-default field.
   *
   * @throws \CRM_Core_Exception
   */
  public function testCustomRuleWithAddress() {
    $this->setupForGroupDedupe();

    $ruleGroup = $this->callAPISuccess('RuleGroup', 'create', [
      'contact_type' => 'Individual',
      'threshold' => 10,
      'used' => 'General',
      'name' => 'TestRule',
      'title' => 'TestRule',
      'is_reserved' => 0,
    ]);
    $rules = [];
    foreach (['postal_code'] as $field) {
      $rules[$field] = $this->callAPISuccess('Rule', 'create', [
        'dedupe_rule_group_id' => $ruleGroup['id'],
        'rule_table' => 'civicrm_address',
        'rule_weight' => 10,
        'rule_field' => $field,
      ]);
    }
    $foundDupes = CRM_Dedupe_Finder::dupesInGroup($ruleGroup['id'], $this->groupID);
    $this->assertEquals(count($foundDupes), 1);
    CRM_Dedupe_Finder::dupes($ruleGroup['id']);

  }

  /**
   * Test rule from Richard
   *
   * @throws \CRM_Core_Exception
   */
  public function testRuleThreeContactFieldsEqualWeightWIthThresholdtheTotalSumOfAllWeight() {
    $this->setupForGroupDedupe();

    $ruleGroup = $this->callAPISuccess('RuleGroup', 'create', [
      'contact_type' => 'Individual',
      'threshold' => 30,
      'used' => 'General',
      'name' => 'TestRule',
      'title' => 'TestRule',
      'is_reserved' => 0,
    ]);

    foreach (['first_name', 'last_name', 'birth_date'] as $field) {
      $rules[$field] = $this->callAPISuccess('Rule', 'create', [
        'dedupe_rule_group_id' => $ruleGroup['id'],
        'rule_table' => 'civicrm_contact',
        'rule_weight' => 10,
        'rule_field' => $field,
      ]);
    }
    $foundDupes = CRM_Dedupe_Finder::dupesInGroup($ruleGroup['id'], $this->groupID);
    $this->assertCount(1, $foundDupes);
  }

  /**
   * Test a custom rule with a non-default field.
   *
   * @throws \CRM_Core_Exception
   */
  public function testInclusiveRule() {
    $this->setupForGroupDedupe();

    $ruleGroup = $this->createRuleGroup();
    foreach (['first_name', 'last_name'] as $field) {
      $rules[$field] = $this->callAPISuccess('Rule', 'create', [
        'dedupe_rule_group_id' => $ruleGroup['id'],
        'rule_table' => 'civicrm_contact',
        'rule_weight' => 4,
        'rule_field' => $field,
      ]);
    }
    $foundDupes = CRM_Dedupe_Finder::dupesInGroup($ruleGroup['id'], $this->groupID);
    $this->assertCount(4, $foundDupes);
    CRM_Dedupe_Finder::dupes($ruleGroup['id']);
  }

  /**
   * Test the supervised dedupe rule against a group.
   *
   * @throws \Exception
   */
  public function testSupervisedDupes() {
    $this->setupForGroupDedupe();
    $ruleGroup = $this->callAPISuccessGetSingle('RuleGroup', ['is_reserved' => 1, 'contact_type' => 'Individual', 'used' => 'Supervised']);
    $foundDupes = CRM_Dedupe_Finder::dupesInGroup($ruleGroup['id'], $this->groupID);
    // -------------------------------------------------------------------------
    // default dedupe rule: threshold = 20 => (First + Last + Email) Matches ( 1 pair )
    // --------------------------------------------------------------------------
    // will   - dale - will@example.com
    // will   - dale - will@example.com
    // so 1 pair for - first + last + mail
    $this->assertEquals(count($foundDupes), 1, 'Check Individual-Fuzzy dupe rule for dupesInGroup().');
  }

  /**
   * Test dupesByParams function.
   *
   * @throws \CRM_Core_Exception
   */
  public function testDupesByParams() {
    // make dupe checks based on based on following contact sets:
    // FIRST - LAST - EMAIL
    // ---------------------------------
    // robin  - hood - robin@example.com
    // robin  - hood - hood@example.com
    // robin  - dale - robin@example.com
    // little - dale - dale@example.com
    // will   - dale - dale@example.com
    // will   - dale - will@example.com
    // will   - dale - will@example.com

    // contact data set
    // FIXME: move create params to separate function
    $params = [
      [
        'first_name' => 'robin',
        'last_name' => 'hood',
        'email' => 'robin@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'robin',
        'last_name' => 'hood',
        'email' => 'hood@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'robin',
        'last_name' => 'dale',
        'email' => 'robin@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'little',
        'last_name' => 'dale',
        'email' => 'dale@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'will',
        'last_name' => 'dale',
        'email' => 'dale@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'will',
        'last_name' => 'dale',
        'email' => 'will@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'will',
        'last_name' => 'dale',
        'email' => 'will@example.com',
        'contact_type' => 'Individual',
      ],
    ];

    $this->hookClass->setHook('civicrm_findDuplicates', [$this, 'hook_civicrm_findDuplicates']);

    $count = 1;

    foreach ($params as $param) {
      $contact = $this->callAPISuccess('contact', 'create', $param);
      $params = [
        'contact_id' => $contact['id'],
        'street_address' => 'Ambachtstraat 23',
        'location_type_id' => 1,
      ];
      $this->callAPISuccess('address', 'create', $params);
      $contactIds[$count++] = $contact['id'];
    }

    // verify that all contacts have been created separately
    $this->assertEquals(count($contactIds), 7, 'Check for number of contacts.');

    $fields = [
      'first_name' => 'robin',
      'last_name' => 'hood',
      'email' => 'hood@example.com',
      'street_address' => 'Ambachtstraat 23',
    ];
    CRM_Core_TemporaryErrorScope::useException();
    $ids = CRM_Contact_BAO_Contact::getDuplicateContacts($fields, 'Individual', 'General', [], TRUE, NULL, ['event_id' => 1]);

    // Check with default Individual-General rule
    $this->assertEquals(count($ids), 2, 'Check Individual-General rule for dupesByParams().');

    // delete all created contacts
    foreach ($contactIds as $contactId) {
      $this->contactDelete($contactId);
    }
  }

  /**
   * Implements hook_civicrm_findDuplicates().
   *
   * Locks in expected params
   *
   */
  public function hook_civicrm_findDuplicates($dedupeParams, &$dedupeResults, $contextParams) {
    $expectedDedupeParams = [
      'check_permission' => TRUE,
      'contact_type' => 'Individual',
      'rule' => 'General',
      'rule_group_id' => NULL,
      'excluded_contact_ids' => [],
    ];
    foreach ($expectedDedupeParams as $key => $value) {
      $this->assertEquals($value, $dedupeParams[$key]);
    }
    $expectedDedupeResults = [
      'ids' => [],
      'handled' => FALSE,
    ];
    foreach ($expectedDedupeResults as $key => $value) {
      $this->assertEquals($value, $dedupeResults[$key]);
    }

    $expectedContext = ['event_id' => 1];
    foreach ($expectedContext as $key => $value) {
      $this->assertEquals($value, $contextParams[$key]);
    }

    return $dedupeResults;
  }

  /**
   * Set up a group of dedupable contacts.
   *
   * @throws \CRM_Core_Exception
   */
  protected function setupForGroupDedupe() {
    $params = [
      'name' => 'Dupe Group',
      'title' => 'New Test Dupe Group',
      'domain_id' => 1,
      'is_active' => 1,
      'visibility' => 'Public Pages',
    ];

    $result = $this->callAPISuccess('group', 'create', $params);
    $this->groupID = $result['id'];

    $params = [
      [
        'first_name' => 'robin',
        'last_name' => 'hood',
        'email' => 'robin@example.com',
        'contact_type' => 'Individual',
        'birth_date' => '2016-01-01',
        'api.Address.create' => ['street_address' => '123 Happy world', 'location_type_id' => 'Billing', 'postal_code' => '99999'],
      ],
      [
        'first_name' => 'robin',
        'last_name' => 'hood',
        'email' => 'hood@example.com',
        'contact_type' => 'Individual',
        'birth_date' => '2016-01-01',
        'api.Address.create' => ['street_address' => '123 Happy World', 'location_type_id' => 'Billing', 'postal_code' => '99999'],
      ],
      [
        'first_name' => 'robin',
        'last_name' => 'dale',
        'email' => 'robin@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'little',
        'last_name' => 'dale',
        'email' => 'dale@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'will',
        'last_name' => 'dale',
        'email' => 'dale@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'will',
        'last_name' => 'dale',
        'email' => 'will@example.com',
        'contact_type' => 'Individual',
      ],
      [
        'first_name' => 'will',
        'last_name' => 'dale',
        'email' => 'will@example.com',
        'contact_type' => 'Individual',
      ],
    ];

    $count = 1;
    foreach ($params as $param) {
      $contact = $this->callAPISuccess('contact', 'create', $param);
      $this->contactIDs[$count++] = $contact['id'];

      $grpParams = [
        'contact_id' => $contact['id'],
        'group_id' => $this->groupID,
      ];
      $this->callAPISuccess('group_contact', 'create', $grpParams);
    }

    // verify that all contacts have been created separately
    $this->assertEquals(count($this->contactIDs), 7, 'Check for number of contacts.');
  }

}
