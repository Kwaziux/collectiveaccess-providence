<?php
/* ----------------------------------------------------------------------
 * app/service/controllers/EditController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2021 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
require_once(__CA_LIB_DIR__.'/Service/GraphQLServiceController.php');
require_once(__CA_APP_DIR__.'/service/schemas/EditSchema.php');
require_once(__CA_APP_DIR__.'/service/helpers/EditHelpers.php');
require_once(__CA_APP_DIR__.'/service/helpers/SearchHelpers.php');

use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQLServices\Schemas\EditSchema;
use GraphQLServices\Helpers\Edit;


class EditController extends \GraphQLServices\GraphQLServiceController {
	# -------------------------------------------------------
	#
	static $config = null;
	# -------------------------------------------------------
	/**
	 *
	 */
	public function __construct(&$request, &$response, $view_paths) {
		parent::__construct($request, $response, $view_paths);
	}
	
	/**
	 *
	 */
	public function _default(){
		$qt = new ObjectType([
			'name' => 'Query',
			'fields' => [
				
			]
		]);
		
		$mt = new ObjectType([
			'name' => 'Mutation',
			'fields' => [
				// ------------------------------------------------------------
				'add' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Add a new record'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'table',
							'type' => Type::string(),
							'description' => _t('Table name. (Eg. ca_objects)')
						],
						[
							'name' => 'type',
							'type' => Type::string(),
							'description' => _t('Type code for new record. (Eg. ca_objects)')
						],
						[
							'name' => 'idno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value for new record.')
						],
						[
							'name' => 'bundles',
							'type' => Type::listOf(EditSchema::get('Bundle')),
							'description' => _t('Bundles to add')
						],
						[
							'name' => 'records',
							'type' => Type::listOf(EditSchema::get('Record')),
							'default' => null,
							'description' => _t('List of records to insert')
						],
						[
							'name' => 'relationships',
							'type' => Type::listOf(EditSchema::get('Relationship')),
							'default' => null,
							'description' => _t('List of relationship to create for new record')
						],
						[
							'name' => 'replaceRelationships',
							'type' => Type::boolean(),
							'default' => false,
							'description' => 'Set to 1 to indicate all relationships are to replaced with those specified in the current request. If not set relationships are merged with existing ones.'
						],
						[
							'name' => 'insertMode',
							'type' => Type::string(),
							'default' => 'FLAT',
							'description' => _t('Insert mode: "FLAT" inserts each record separated; "HIERARCHICAL" creates a hierarchy from the list (if the specified table support hierarchies).')
						],
						[
							'name' => 'matchOn',
							'type' => Type::listOf(Type::string()),
							'default' => ['idno'],
							'description' => _t('List of fields to test for existance of record. Values can be "idno" or "preferred_labels".')
						],
						[
							'name' => 'existingRecordPolicy',
							'type' => Type::string(),
							'default' => 'SKIP',
							'description' => _t('Policy if record with same identifier already exists. Values are: IGNORE (ignore existing records, REPLACE (delete existing and create new), MERGE (execute as edit), SKIP (do not perform add).')
						],
						[
							'name' => 'ignoreType',
							'type' => Type::boolean(),
							'default' => false,
							'description' => _t('Ignore record type when looking for existing records.')
						],
						[
							'name' => 'match',
							'type' => EditSchema::get('MatchRecord'),
							'description' => _t('Find criteria')
						],
						[
							'name' => 'list',
							'type' => Type::string(),
							'default' => false,
							'description' => _t('List to add records to (when inserting list items.')
						],
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						$errors = $warnings = $ids = $idnos = [];
						
						$table = $args['table'];
						$insert_mode = strtoupper($args['insertMode']);
						$erp = strtoupper($args['existingRecordPolicy']);
						$match_on = (is_array($args['matchOn']) && sizeof($args['matchOn'])) ? $args['matchOn'] : ['idno'];
						$ignore_type = $args['ignoreType'];
						
						$idno_fld = \Datamodel::getTableProperty($table, 'ID_NUMBERING_ID_FIELD');
						
						if(!$args['idno'] && $args['identifier']) { $args['idno'] = $args['identifier']; }
						
						$records = (is_array($args['records']) && sizeof($args['records'])) ? $args['records'] : [[
							'idno' => $args['idno'],
							'type' => $args['type'],
							'bundles' => $args['bundles'],
							'match' => $args['match'],
							'relationships' => $args['relationships'],
							'replaceRelationships' => $args['replaceRelationships']
						]];
						
						$c = 0;
						$last_id = null;
						foreach($records as $record) {
							$instance = null;
							
							if(!$record['idno'] && $record['identifier']) { $record['idno'] = $record['identifier']; }
							
							// Does record already exist?
							try {
								if(in_array($erp, ['SKIP', 'REPLACE', 'MERGE'])) {
									if(is_array($f = $record['match'])) {										
										if($f['restrictToTypes'] && !is_array($f['restrictToTypes'])) {
											$f['restrictToTypes'] = [$f['restrictToTypes']];
										}
										if(isset($f['search'])) {
											$s = caGetSearchInstance($table);
						
											if(is_array($f['restrictToTypes']) && sizeof($f['restrictToTypes'])) {
												$s->setTypeRestrictions($f['restrictToTypes']);
											}
				
											if(($qr = $s->search($f['search'])) && $qr->nextHit()) {
												$instance = $qr->getInstance();
											}
										} elseif(isset($f['criteria'])) {
											if(($qr = $table::find(\GraphQLServices\Helpers\Search\convertCriteriaToFindSpec($f['criteria'], $table), ['returnAs' => 'searchResult', 'allowWildcards' => true, 'restrictToTypes' => $f['restrictToTypes']])) && $qr->nextHit()) {
												$instance = $qr->getInstance();
											}
										}
									} else {
										foreach($match_on as $m) {
											try {
												switch($m) {
													case 'idno':
														$instance = (in_array($erp, ['SKIP', 'REPLACE', 'MERGE'])) ? self::resolveIdentifier($table, $record['idno'], $ignore_type ? null : $record['type'], ['idnoOnly' => true, 'list' => $args['list']]) : null;
														break;
													case 'preferred_labels':
														$label_values = \GraphQLServices\Helpers\Edit\extractLabelValueFromBundles($table, $record['bundles']);
														$instance = self::resolveLabel($table, $label_values, $ignore_type ? null : $record['type'], ['list' => $args['list']]);
											
														break;
												}
											} catch(\ServiceException $e) {
												// No matching record
											}
										}
									}
								}
								
							} catch(\ServiceException $e) {
								$instance = null;	// No matching record
							}
							
							switch($erp) {
								case 'SKIP':
									if($instance) { 
										$ids[] = $last_id = $instance->getPrimaryKey(); 
										continue(2);
									}
									break;
								case 'REPLACE':
									if($instance && !$instance->delete(true)) {
										foreach($instance->errors() as $e) {
											$errors[] = [
												'code' => $e->getErrorNumber(),
												'message' => $e->getErrorDescription(),
												'bundle' => 'GENERAL'
											];
										}		
									}
									$instance = null;
									break;
								case 'MERGE':
									// NOOP
									break;
								case 'IGNORE':
									// NOOP
									break;
							}
							
							if ($instance) {
								$ret = true;
							} else {
								// Create new record
								$instance = new $table();
								$instance->set($idno_fld, $record['idno']);
								$instance->set('type_id', $record['type']);
								if($instance->hasField('list_id') && ($instance->primaryKey() !== 'list_id')) { 
									if(!$args['list']) { throw new \ServiceException(_t('List must be specified')); }
									$instance->set('list_id', $args['list']); 
								}
							
								if($insert_mode === 'HIERARCHICAL') {
									$instance->set('parent_id', $last_id);
								}
								$ret = $instance->insert(['validateAllIdnos' => true]);
							}
							if(!$ret) {
								foreach($instance->errors() as $e) {
									$errors[] = [
										'code' => $e->getErrorNumber(),
										'message' => $e->getErrorDescription(),
										'bundle' => 'GENERAL'
									];
								}	
							} else {
								$ids[] = $last_id = $instance->getPrimaryKey();
								$idnos[] = $instance->get($idno_fld);
								
								$ret = self::processBundles($instance, $record['bundles']);
								$errors += $ret['errors'];
								$warnings += $ret['warnings'];
								if(isset($record['relationships']) && is_array($record['relationships']) && sizeof($record['relationships'])) {
									$ret = self::processRelationships($instance, $record['relationships'], ['replace' => $record['replaceRelationships']]);
									$errors += $ret['errors'];
									$warnings += $ret['warnings'];
								}
							
								$c++;
							}
						}
						
						return ['table' => $table, 'id' => $ids, 'idno' => $idnos, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				],
				'edit' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Edit an existing record'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'table',
							'type' => Type::string(),
							'description' => _t('Table name. (Eg. ca_objects)')
						],
						[
							'name' => 'type',
							'type' => Type::string(),
							'description' => _t('Type code for new record. (Eg. ca_objects)')
						],
						[
							'name' => 'id',
							'type' => Type::int(),
							'description' => _t('Numeric database id value of record to edit.')
						],
						[
							'name' => 'idno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value of record to edit.')
						],
						[
							'name' => 'identifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to edit.')
						],
						[
							'name' => 'bundles',
							'type' => Type::listOf(EditSchema::get('Bundle')),
							'description' => _t('Bundles to add')
						],
						[
							'name' => 'records',
							'type' => Type::listOf(EditSchema::get('Record')),
							'description' => _t('List of records to edit')
						],
						[
							'name' => 'relationships',
							'type' => Type::listOf(EditSchema::get('Relationship')),
							'default' => null,
							'description' => _t('List of relationship to create for new record')
						],
						[
							'name' => 'replaceRelationships',
							'type' => Type::boolean(),
							'default' => false,
							'description' => 'Set to 1 to indicate all relationships are to replaced with those specified in the current request. If not set relationships are merged with existing ones..'
						],
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						$errors = $warnings = $ids = $idnos = [];
						
						$table = $args['table'];
						$idno_fld = \Datamodel::getTableProperty($table, 'ID_NUMBERING_ID_FIELD');
						
						$records = (is_array($args['records']) && sizeof($args['records'])) ? $args['records'] : [[
							'identifier' => $args['identifier'],
							'id' => $args['id'],
							'idno' => $args['idno'],
							'type' => $args['type'],
							'bundles' => $args['bundles'],
							'relationships' => $args['relationships'],
							'replaceRelationships' => $args['replaceRelationships'],
							'options' => []
						]];
						
						$c = 0;
						$opts = [];
						foreach($records as $record) {
							list($identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($record);
							if(!($instance = self::resolveIdentifier($table, $identifier, $record['type'], $opts))) {
								$errors[] = [
									'code' => 100,	// TODO: real number?
									'message' => _t('Invalid identifier'),
									'bundle' => 'GENERAL'
								];
							} else {
								$ids[] = $instance->getPrimaryKey();
								$idnos[] = $instance->get($idno_fld);
								
								$ret = self::processBundles($instance, $record['bundles']);
								$errors += $ret['errors'];
								$warnings += $ret['warnings'];
								
								if(isset($record['relationships']) && is_array($record['relationships']) && sizeof($record['relationships'])) {
									$ret = self::processRelationships($instance, $record['relationships'], ['replace' => $record['replaceRelationships']]);
									$errors += $ret['errors'];
									$warnings += $ret['warnings'];
								}
								
								$c++;
							}
						}
						
						return ['table' => $table, 'id' => $ids, 'idno' => $idnos, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				],
				'delete' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Delete an existing record'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'table',
							'type' => Type::string(),
							'description' => _t('Table name. (Eg. ca_objects)')
						],
						[
							'name' => 'id',
							'type' => Type::int(),
							'description' => _t('Numeric database id value of record to edit.')
						],
						[
							'name' => 'ids',
							'type' => Type::listOf(Type::int()),
							'description' => _t('Numeric database id value of record to edit.')
						],
						[
							'name' => 'idno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value of record to edit.')
						],
						[
							'name' => 'idnos',
							'type' => Type::listOf(Type::string()),
							'description' => _t('Alphanumeric idno value of record to edit.')
						],
						[
							'name' => 'identifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to delete.')
						],
						[
							'name' => 'identifiers',
							'type' => Type::listOf(Type::string()),
							'description' => _t('Alphanumeric idno value or numeric database id of record to delete.')
						]
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						$errors = $warnings = [];
						
						$table = $args['table'];
						
						$opts = [];
						$identifiers = [];
						
						if(isset($args['identifiers']) && is_array($args['identifiers']) && sizeof($args['identifiers'])) {
							$identifiers = $args['identifiers'];
						} elseif(isset($args['identifier']) && (strlen($args['identifier']) > 0)) {
							$identifiers[] = $args['identifier'];
						} elseif(isset($args['ids']) && is_array($args['ids']) && sizeof($args['ids'])) {
							$identifiers = $args['ids'];
							$opts['primaryKeyOnly'] = true;
						} elseif(isset($args['idnos']) && is_array($args['idnos']) && sizeof($args['idnos'])) {
							$identifiers = $args['idnos'];
							$opts['idnoOnly'] = true;
						} elseif(isset($args['id']) && ($args['id'] > 0)) {
							$identifiers[] = $args['id'];
							$opts['primaryKeyOnly'] = true;
						} elseif(isset($args['idno']) && (strlen($args['idno']) > 0)) {
							$identifiers[] = $args['idno'];
							$opts['idnoOnly'] = true;
						}
						
						$c = 0;
						foreach($identifiers as $identifier) {
							try {
								if(!($instance = self::resolveIdentifier($table, $identifier, null, $opts))) {
									$errors[] = [
										'code' => 100,	// TODO: real number?
										'message' => _t('Invalid identifier'),
										'bundle' => 'GENERAL'
									];
								} elseif(!($rc = $instance->delete(true))) {
									foreach($instance->errors() as $e) {
										$errors[] = [
											'code' => $e->getErrorNumber(),
											'message' => $e->getErrorDescription(),
											'bundle' => null
										];
									}
								} else {
									$c++;
								}
							} catch (ServiceException $e) {
								$errors[] = [
									'code' => 284,
									'message' => $e->getMessage(),
									'bundle' => null
								];
							}
						}
						
						return ['table' => $table, 'id' => null, 'idno' => null, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				],
				'truncate' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Truncate a table, removing all records or records created from a date range.'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'table',
							'type' => Type::string(),
							'description' => _t('Table name. (Eg. ca_objects)')
						],
						[
							'name' => 'date',
							'type' => Type::string(),
							'description' => _t('Limit truncation to rows with modification dates within the specified range. Date can be any parseable date expression.')
						],
						[
							'name' => 'type',
							'type' => Type::string(),
							'description' => _t('Type code to limit truncation of records to.')
						],
						[
							'name' => 'types',
							'type' => Type::listOf(Type::string()),
							'description' => _t('Type codes to limit truncation of records to.')
						],
						[
							'name' => 'fast',
							'type' => Type::boolean(),
							'description' => _t('Delete records quickly, bypassing log and search index updates.')
						],
						[
							'name' => 'list',
							'type' => Type::string(),
							'description' => _t('Delete all items from specified list. Implies table = ca_list_items. If set table option is ignored.')
						],
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						if(!$u->canDoAction('can_truncate_tables_via_graphql')) {
							throw new \ServiceException(_t('Access denied'));
						}
						
						$errors = $warnings = [];
						
						
						if($list = $args['list']) {
							$table = 'ca_list_items';
							$qr = $table::find(['list_id' => $list], ['modified' => $args['date'], 'restrictToTypes' => (isset($args['types']) && is_array($args['types']) && sizeof($args['types'])) ? $args['types'] : ($args['type'] ?? [$args['type']]), 'returnAs' => 'searchResult']);
						} else {
							$table = $args['table'];
							$qr = $table::find('*', ['modified' => $args['date'], 'restrictToTypes' => (isset($args['types']) && is_array($args['types']) && sizeof($args['types'])) ? $args['types'] : ($args['type'] ?? [$args['type']]), 'returnAs' => 'searchResult']);
						}
						$c = 0;
						if($qr && ($qr->numHits() > 0)) {
							if((bool)$args['fast'] && Datamodel::getFieldNum($table, 'deleted')) {
								$db = new Db();
								$pk = Datamodel::primaryKey($table);
							
								try {
									if($args['date'] || $args['types']) {
										$db->query("UPDATE {$table} SET deleted = 1 WHERE {$pk} IN (?)", [$qr->getAllFieldValues("{$table}.{$pk}")]);
									} else {
										$db->query("UPDATE {$table} SET deleted = 1");
									}
									$c = $qr->numHits();
								} catch (Exception $e) {
									$errors[] = [
										'code' => 1000,		// TODO: use real code
										'message' => $e->getMessage(),
										'bundle' => null
									];
								}						
							} else {
								while($qr->nextHit()) {
									$instance = $qr->getInstance();
									if(!$instance->delete(true)) {
										foreach($instance->errors() as $e) {
											$errors[] = [
												'code' => $e->getErrorNumber(),
												'message' => $e->getErrorDescription(),
												'bundle' => null
											];
										}
									} else {
										$c++;
									}
								}
							}
						}
						
						
						
						return ['table' => $table, 'id' => null, 'idno' => null, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				],
				//
				// Relationships
				//
				'addRelationship' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Add a relationship between two records'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'subject',
							'type' => Type::string(),
							'description' => _t('Subject table name. (Eg. ca_objects)')
						],
						[
							'name' => 'subjectId',
							'type' => Type::int(),
							'description' => _t('Numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'subjectIdno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value to use as relationship subject.')
						],
						[
							'name' => 'subjectIdentifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'target',
							'type' => Type::string(),
							'description' => _t('Target table name. (Eg. ca_objects)')
						],
						[
							'name' => 'targetId',
							'type' => Type::int(),
							'description' => _t('Numeric database id of record to use as relationship target.')
						],
						[
							'name' => 'targetIdno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value of record to use as relationship target.')
						],
						[
							'name' => 'targetIdentifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to use as relationship target.')
						],
						[
							'name' => 'relationshipType',
							'type' => Type::string(),
							'description' => _t('Relationship type code.')
						],
						[
							'name' => 'bundles',
							'type' => Type::listOf(EditSchema::get('Bundle')),
							'description' => _t('Bundles to add')
						]
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						$errors = $warnings = [];
						
						$subject = $args['subject'];
						$target = $args['target'];
						
						$reltype = $args['relationshipType'];
						$bundles = caGetOption('bundles', $args, [], ['castTo' => 'array']);
						
						list($subject_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($args, 'subject');
						if(!($subject = self::resolveIdentifier($subject, $subject_identifier, null, $opts))) {
							throw new \ServiceException(_t('Invalid subject identifier'));
						}
						
						// Check privs
						if (!$subject->isSaveable($u)) {
							throw new \ServiceException(_t('Cannot access subject'));
						}
						
						// effective_date set?
						$effective_date = \GraphQLServices\Helpers\Edit\extractValueFromBundles($bundles, ['effective_date']);
						
						$c = 0;
						list($target_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($args, 'target');
						if(!($rel = $subject->addRelationship($target, $target_identifier, $reltype, $effective_date, null, null, null, $opts))) {
							$errors[] = [
								'code' => 100,	// TODO: real number?
								'message' => _t('Could not create relationship: %1', join('; ', $subject->getErrors())),
								'bundle' => 'GENERAL'
							];
						} elseif(sizeof($bundles) > 0) {
							//  Add interstitial data
							if (is_array($ret = self::processBundles($rel, $bundles))) {
								$errors += $ret['errors'];
								$warnings += $ret['warnings'];
							}
							$c++;
						}
						
						return ['table' => is_object($rel) ? $rel->tableName() : null, 'id' => is_object($rel) ?  [$rel->getPrimaryKey()] : null, 'idno' => null, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				],
				'editRelationship' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Edit a relationship between two records'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'id',
							'type' => Type::int(),
							'defaultValue' => null,
							'description' => _t('Relationship id')
						],
						[
							'name' => 'subject',
							'type' => Type::string(),
							'description' => _t('Subject table name. (Eg. ca_objects)')
						],
						[
							'name' => 'subjectId',
							'type' => Type::int(),
							'description' => _t('Numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'subjectIdno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value to use as relationship subject.')
						],
						[
							'name' => 'subjectIdentifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'target',
							'type' => Type::string(),
							'description' => _t('Target table name. (Eg. ca_objects)')
						],
						[
							'name' => 'targetId',
							'type' => Type::int(),
							'description' => _t('Numeric database id of record to use as relationship target.')
						],
						[
							'name' => 'targetIdno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value of record to use as relationship target.')
						],
						[
							'name' => 'targetIdentifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to use as relationship target.')
						],
						[
							'name' => 'relationshipType',
							'type' => Type::string(),
							'description' => _t('Relationship type code.')
						],
						[
							'name' => 'bundles',
							'type' => Type::listOf(EditSchema::get('Bundle')),
							'description' => _t('Bundles to edit')
						]
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						$errors = $warnings = [];
						
						$s = $t = null;				
						$subject = $args['subject'];
						$target = $args['target'];
						$target_identifier = $args['targetIdentifier'];
						$rel_type = $args['relationshipType'];
						$bundles = caGetOption('bundles', $args, [], ['castTo' => 'array']);
						
						// effective_date set?
						$effective_date = \GraphQLServices\Helpers\Edit\extractValueFromBundles($bundles, ['effective_date']);
						
						// rel type set?
						$new_rel_type = \GraphQLServices\Helpers\Edit\extractValueFromBundles($bundles, ['relationship_type']);
						
						list($subject_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($args, 'subject');
						if(!($s = self::resolveIdentifier($subject, $subject_identifier, $opts))) {
							throw new \ServiceException(_t('Subject does not exist'));
						}
						if (!$s->isSaveable($u)) {
							throw new \ServiceException(_t('Subject is not accessible'));
						}
						
						if(!($rel_id = $args['id'])) {		
							list($target_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($args, 'target');
							if(!($t = self::resolveIdentifier($target, $target_identifier, $opts))) {
								throw new \ServiceException(_t('Target does not exist'));
							}
							
							if ($rel = \GraphQLServices\Helpers\Edit\getRelationship($u, $s, $t, $rel_type)) {
								$rel_id = $rel->getPrimaryKey();
							}
						} 
						if(!$rel_id) {
							throw new \ServiceException(_t('Relationship does not exist'));
						}
						
						$c = 0;
						if(!($rel = $s->editRelationship($target, $rel_id, $target_identifier, $new_rel_type, $effective_date))) {
							$errors[] = [
								'code' => 100,	// TODO: real number?
								'message' => _t('Could not edit relationship: %1', join('; ', $s->getErrors())),
								'bundle' => 'GENERAL'
							];		
						} elseif(sizeof($bundles) > 0) {
							//  Edit interstitial data
							if (is_array($ret = self::processBundles($rel, $bundles))) {
								$errors += $ret['errors'];
								$warnings += $ret['warnings'];
							}
							$c++;
						}
						
						return ['table' => is_object($rel) ? $rel->tableName() : null, 'id' => is_object($rel) ?  [$rel->getPrimaryKey()] : null, 'idno' => null, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				],
				'deleteRelationship' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Delete relationship'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'id',
							'type' => Type::int(),
							'defaultValue' => null,
							'description' => _t('Relationship id')
						],
						[
							'name' => 'subject',
							'type' => Type::string(),
							'description' => _t('Subject table name. (Eg. ca_objects)')
						],
						[
							'name' => 'subjectId',
							'type' => Type::int(),
							'description' => _t('Numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'subjectIdno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value to use as relationship subject.')
						],
						[
							'name' => 'subjectIdentifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'target',
							'type' => Type::string(),
							'description' => _t('Target table name. (Eg. ca_objects)')
						],
						[
							'name' => 'targetId',
							'type' => Type::int(),
							'description' => _t('Numeric database id of record to use as relationship target.')
						],
						[
							'name' => 'targetIdno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value of record to use as relationship target.')
						],
						[
							'name' => 'targetIdentifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to use as relationship target.')
						],
						[
							'name' => 'relationshipType',
							'type' => Type::string(),
							'description' => _t('Relationship type code.')
						]
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						$errors = $warnings = [];
						
						$rel_type = $s = $t = null;				
						$subject = $args['subject'];
						$target = $args['target'];
						
						list($subject_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($args, 'subject');
						if(!($s = self::resolveIdentifier($subject, $subject_identifier, $opts))) {
							throw new \ServiceException(_t('Invalid subject identifier'));
						}
						if (!$s->isSaveable($u)) {
							throw new \ServiceException(_t('Subject is not accessible'));
						}
						
						if(!($rel_id = $args['id'])) {		
							$rel_type = $args['relationshipType'];
							
							list($target_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($args, 'target');
							if(!($t = self::resolveIdentifier($target, $target_identifier, $opts))) {
								throw new \ServiceException(_t('Invalid target identifier'));
							}
							
							if($rel = \GraphQLServices\Helpers\Edit\getRelationship($u, $s, $t, $rel_type)) {
								$rel_id = $rel->getPrimaryKey();
							}
						} 
						
						if (!$rel_id) {
							throw new \ServiceException(_t('Relationship does not exist'));
						}
						
						$c = 0;
						if(!$s->removeRelationship($target, $rel_id)) {							
							$errors[] = [
								'code' => 100,	// TODO: real number?
								'message' => _t('Could not delete relationship: %1', join('; ', $s->getErrors())),
								'bundle' => 'GENERAL'
							];
						} else {
							$c++;
						}
						
						return ['table' => is_object($s) ? $s->tableName() : null, 'id' => is_object($s) ?  [$s->getPrimaryKey()] : null, 'idno' => null, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				],
				'deleteAllRelationships' => [
					'type' => EditSchema::get('EditResult'),
					'description' => _t('Delete all relationships on record to a target table. If one or more relationship types are specified then only relationships with those types will be removed.'),
					'args' => [
						[
							'name' => 'jwt',
							'type' => Type::string(),
							'description' => _t('JWT'),
							'defaultValue' => self::getBearerToken()
						],
						[
							'name' => 'subject',
							'type' => Type::string(),
							'description' => _t('Subject table name. (Eg. ca_objects)')
						],
						[
							'name' => 'subjectId',
							'type' => Type::int(),
							'description' => _t('Numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'subjectIdno',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value to use as relationship subject.')
						],
						[
							'name' => 'subjectIdentifier',
							'type' => Type::string(),
							'description' => _t('Alphanumeric idno value or numeric database id of record to use as relationship subject.')
						],
						[
							'name' => 'target',
							'type' => Type::string(),
							'description' => _t('Target table name. (Eg. ca_objects)')
						],
						[
							'name' => 'relationshipType',
							'type' => Type::string(),
							'description' => _t('Relationship type code.')
						],
						[
							'name' => 'relationshipTypes',
							'type' => Type::listOf(Type::string()),
							'description' => _t('List of relationship type codes.')
						]
					],
					'resolve' => function ($rootValue, $args) {
						$u = self::authenticate($args['jwt']);
						
						$errors = $warnings = [];
						
						$rel_types = $s = $t = null;				
						$subject = $args['subject'];
						$target = $args['target'];
						
						
						list($subject_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($args, 'subject');
						if(!($s = self::resolveIdentifier($subject, $subject_identifier, $opts))) {
							throw new \ServiceException(_t('Invalid subject identifier'));
						}
						if (!$s->isSaveable($u)) {
							throw new \ServiceException(_t('Subject is not accessible'));
						}
						
						if(isset($args['relationshipTypes']) && is_array($args['relationshipTypes']) && sizeof($$args['relationshipTypes'])) {
							$rel_types = $args['relationshipTypes'];
						} elseif(isset($args['relationshipType']) && $args['relationshipType']) {
							$rel_types = [$args['relationshipType']];
						} else {
							$rel_types = null;
						}
							
						$c = 0;
						if(is_array($rel_types) && sizeof($rel_types)) {
							foreach($rel_types as $rel_type) {
								if(!$s->removeRelationships($target, $rel_type)) {
									$errors[] = [
										'code' => 100,	// TODO: real number?
										'message' => _t('Could not delete relationships for relationship type %1: %2', $rel_type, join('; ', $s->getErrors())),
										'bundle' => 'GENERAL'
									];
									continue;
								} 
								$c++;
							}
						} else {
							if(!$s->removeRelationships($target)) {
								$errors[] = [
									'code' => 100,	// TODO: real number?
									'message' => _t('Could not delete relationships: %1', join('; ', $s->getErrors())),
									'bundle' => 'GENERAL'
								];
							} else {
								$c++;
							}
						}
						
						return ['table' => is_object($s) ? $s->tableName() : null, 'id' => is_object($s) ?  [$s->getPrimaryKey()] : null, 'idno' => null, 'errors' => $errors, 'warnings' => $warnings, 'changed' => $c];
					}
				]
			]
		]);
		
		return self::resolve($qt, $mt);
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private static function processBundles(\BaseModel $instance, array $bundles) : array {
		$errors = $warnings = [];
		foreach($bundles as $b) {
			$id = $b['id'] ?? null;
			$delete = isset($b['delete']) ? (bool)$b['delete'] : false;
			$replace = isset($b['replace']) ? (bool)$b['replace'] : false;
			$bundle_name = $b['name'];
			
			if(!strlen($bundle_name)) { continue; }
			
			switch($bundle_name) {
				# -----------------------------------
				case 'effective_date':
				case 'relationship_type':
					// noop - handled by services
					break;
				# -----------------------------------
				case 'preferred_labels':
				case 'nonpreferred_labels':
					$label_values = [];
					
					$label_values = \GraphQLServices\Helpers\Edit\extractLabelValueFromBundles($instance->tableName(), [$b]);
					
					$locale = caGetOption('locale', $b, ca_locales::getDefaultCataloguingLocale());
					$locale_id = caGetOption('locale', $b, ca_locales::codeToID($locale));
					
					$type_id = caGetOption('type_id', $b, null);
					
					if(!$delete && $id) {
						// Edit
						$rc = $instance->editLabel($id, $label_values, $locale_id, $type_id, ($bundle_name === 'preferred_labels'));
					} elseif($replace && !$id) {
						$rc = $instance->replaceLabel($label_values, $locale_id, $type_id, ($bundle_name === 'preferred_labels'));
					} elseif(!$delete && !$id) {
						// Add
						$rc = $instance->addLabel($label_values, $locale_id, $type_id, ($bundle_name === 'preferred_labels'));
					} elseif($delete && $id) {
						// Delete
						$rc = $instance->removeLabel($id);
					} elseif($delete && !$id) {
						// Delete all
						$rc = $instance->removeAllLabels(($bundle_name === 'preferred_labels') ? __CA_LABEL_TYPE_PREFERRED__ : __CA_LABEL_TYPE_NONPREFERRED__);
					} else {
						// invalid operation
						$warnings[] = warning($bundle_name, _t('Invalid operation %1 on %2', ($delete ? _t('delete') : $id ? 'edit' : 'add')));	
					}
					
					foreach($instance->errors() as $e) {
						$errors[] = [
							'code' => $e->getErrorNumber(),
							'message' => $e->getErrorDescription(),
							'bundle' => $bundle_name
						];
					}
					break;
				# -----------------------------------
				default:
					if($instance->hasField($bundle_name)) {
						$instance->set($bundle_name, $delete ? null : ((is_array($b['values']) && sizeof($b['values'])) ? array_shift($b['values']) : $b['value'] ?? null), ['allowSettingOfTypeID' => true]);
						$rc = $instance->update();
					} else {
						 // attribute
						$attr_values = [];
						if (isset($b['values']) && is_array($b['values']) && sizeof($b['values'])) {
							foreach($b['values'] as $val) {
								$attr_values[$val['name']] = $val['value'];
							}
						} elseif(isset($b['value'])) {
							$attr_values[$bundle_name] = $b['value'];
						}
						
						$locale = caGetOption('locale', $b, ca_locales::getDefaultCataloguingLocale());
						$locale_id = caGetOption('locale', $b, ca_locales::codeToID($locale));
						
						$attr_values['locale_id'] = $locale_id;
				
						if(!$delete && $id) {
							// Edit
							if($rc = $instance->editAttribute($id, $bundle_name, $attr_values)) {
								$rc = $instance->update();
							}
						} elseif($replace && !$id) {
							if($rc = $instance->replaceAttribute($attr_values, $bundle_name, null, ['showRepeatCountErrors' => true])) {
								$rc = $instance->update();
							}
						} elseif(!$delete && !$id) {
							// Add
							if($rc = $instance->addAttribute($attr_values, $bundle_name, null, ['showRepeatCountErrors' => true])) {
								$rc = $instance->update();
							}
						} elseif($delete && $id) {
							// Delete
							if($rc = $instance->removeAttribute($id)) {
								$rc = $instance->update();
							}
						} elseif($delete && !$id) {
							// Delete all
							if($rc = $instance->removeAttributes($bundle_name)) {
								$rc = $instance->update();
							}
						} else {
							// invalid operation
							$warnings[] = warning($bundle_name, _t('Invalid operation %1 on %2', ($delete ? _t('delete') : $id ? 'edit' : 'add')));
						}
					}
				
					foreach($instance->errors() as $e) {
						$errors[] = [
							'code' => $e->getErrorNumber(),
							'message' => $e->getErrorDescription(),
							'bundle' => $bundle_name
						];
					}
					break;
				# -----------------------------------
			}
		}
		return ['errors' => $errors, 'warnings' => $warnings];
	}
	# -------------------------------------------------------
	/**
	 * TODO: 
	 *		1. Special handling for self-relations? (Eg. direction)
	 *		2. Options to control when relationship is edited vs. recreated
	 *
	 */
	private static function processRelationships(\BaseModel $instance, array $relationships, ?array $options=null) : array {
		$replace = caGetOption('replace', $options, false);
		$errors = $warnings = [];
		
		if($replace) {
			foreach(array_unique(array_map(function($v) { return $v['target']; }, $relationships)) as $t) {
				$instance->removeRelationships($t);
			}
		}
		
		foreach($relationships as $r) {
			$effective_date = (isset($r['bundles']) && is_array($r['bundles'])) ? \GraphQLServices\Helpers\Edit\extractValueFromBundles($r['bundles'], ['effective_date']) : null;
						
			$c = 0;
			$target = $r['target'];
			list($target_identifier, $opts) = \GraphQLServices\Helpers\Edit\resolveParams($r, 'target');
			
			if(is_array($rel_ids = $instance->relationshipExists($target, $target_identifier, $r['relationshipType']))) {
				$rel_id = array_shift($rel_ids);
				$rel = $instance->editRelationship($target, $rel_id, $target_identifier, $r['relationshipType'], $effective_date, null, null, null, $opts);
			} else {
				$rel = $instance->addRelationship($target, $target_identifier, $r['relationshipType'], $effective_date, null, null, null, $opts);
			}
			if(!$rel) {
				$errors[] = [
					'code' => 100,	// TODO: real number?
					'message' => is_array($rel_ids) ? 
						_t('Could not edit relationship: %1', join('; ', $instance->getErrors())) 
						: 
						_t('Could not create relationship: %1', join('; ', $instance->getErrors())),
					'bundle' => 'GENERAL'
				];
			} elseif(isset($r['bundles']) && is_array($r['bundles']) && (sizeof($r['bundles']) > 0)) {
				//  Add interstitial data
				if (is_array($ret = self::processBundles($rel, $r['bundles']))) {
					$errors += $ret['errors'];
					$warnings += $ret['warnings'];
				}
				$c++;
			}
		}
		return ['errors' => $errors, 'warnings' => $warnings];
	}
	# -------------------------------------------------------
}