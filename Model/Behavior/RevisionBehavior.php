<?php

/**
 * Reverts changes made to a model.
 */
class RevisionBehavior extends ModelBehavior {

	public function beforeFind(Model $model, $queryData) {
		$model->revisionQueryData = $queryData;
		return $queryData;
	}

	public function afterFind(Model $model, $results, $primary = false) {
		parent::afterFind($model, $results, $primary);
		if (!empty($model->revision)) {
			$dataModified = false;
			$Audit = ClassRegistry::init('Audit');
			$Audit->bindModel(array('hasMany' => array('AuditDelta')), false);
			$auditCreated = $Audit->field('created', array(
				'id' => $model->revision
			));
			$audits = $Audit->find('all', array(
				'conditions' => array(
					'Audit.created >=' => $auditCreated,
					'Audit.model' => $model->alias
				),
				'order' => array(
					'Audit.created' => 'desc'
				)
			));
			foreach ($audits as $audit) {
				switch ($audit['Audit']['event']) {
					case 'CREATE':
						foreach ($results as $key => $val) {
							if (!empty($val[$model->alias][$model->primaryKey])) {
								$entity = $val[$model->alias][$model->primaryKey];
								if ($entity == $audit['Audit']['entity_id']) {
									unset($results[$key]);
									$dataModified = true;
								}
							}
						}
						break;

					case 'EDIT':
						foreach ($results as $key => $val) {
							$entity = $val[$model->alias][$model->primaryKey];
							if ($entity == $audit['Audit']['entity_id']) {
								foreach ($audit['AuditDelta'] as $delta) {
									$field = $delta['property_name'];
									$value = $delta['old_value'];
									$results[$key][$model->alias][$field] = $value;
									$dataModified = true;
								}
							}
						}
						break;

					case 'DELETE':
						$data = json_decode($audit['Audit']['json_object']);
						$data = Set::reverse($data);	
						foreach ($model->belongsTo as $key => $value) {
							if (!empty($data[$model->alias][$value['foreignKey']])) {
								$assocModel = ClassRegistry::init($value['className']);
								if (!empty($data[$model->alias][$value['foreignKey']])) {
									$assocData = $assocModel->find('first', array(
										'conditions' => array(
											sprintf('%s.%s', $assocModel->alias, $assocModel->primaryKey) => $data[$model->alias][$value['foreignKey']]
										),
										'contain' => false
									));
									if (!empty($assocData)) {
										$data[$key] = $assocData[$assocModel->alias];
									}
								}
							}
						}
						foreach ($model->hasMany as $key => $value) {
							$assocModel = ClassRegistry::init($value['className']);
							$assocData = $assocModel->find('all', array(
								'conditions' => array(
									sprintf('%s.%s', $assocModel->alias, $value['foreignKey']) => $data[$model->alias][$model->primaryKey]
								),
							));
							if (!empty($assocData)) {
								$item = array();
								foreach ($assocData as $subkey => $subvalue) {
									$tmp = $subvalue[$assocModel->alias];
									unset($subvalue[$assocModel->alias]);
									$item[] = $tmp + $subvalue;
								}
								$data[$key] = $item;
							}
						}
						$results[] = $data;
						$dataModified = true;
						break;

				}
			}
			if (!empty($model->revisionQueryData) & $dataModified) {
				if (!empty($model->revisionQueryData['order'][0])) {
					$order = $model->revisionQueryData['order'][0];
					list($orderModel, $orderField) = explode('.', (key($order)));
					$orderDirection = reset($order);
					$results = Hash::sort(
						$results, 
						sprintf('{n}.%s.%s', $orderModel, $orderField), 
						$orderDirection
					);
				}
				if (!empty($model->revisionQueryData['conditions'])) {
					$conditions = $model->revisionQueryData['conditions'];
					$allowedOperators = array('==', '<=', '>=');
					$allowedModels = array($model->alias);
					$scope = array();
					foreach ($conditions as $conKey => $conValue) {
						$operator = '==';
						if (count($explode1 = explode(' ', $conKey)) > 1) {
							$conKey = $explode1[0];
							if (in_array($explode1[1], $allowedOperators)) {
								$operator = $explode1[1];
							}
						}
						$conModel = $model->alias;
						$conKey2 = $conKey;
						if (count($explode2 = explode('.', $conKey)) > 1) {
							if (in_array($explode2[0], $allowedModels)) {
								$conModel = trim($explode2[0], '`');
							}
							$conKey2 = trim($explode2[1], '`');
						}
						$scope[$conModel][$conKey2][$operator] = $conValue;
					}
					foreach ($results as $key => $value) {
						foreach ($value[$model->alias] as $subkey => $subvalue) {
							if (isset($scope[$model->alias][$subkey])) {
								$scopeOperator = key($scope[$model->alias][$subkey]);
								$scopeValue = reset($scope[$model->alias][$subkey]);
								switch ($scopeOperator) {
									case '==':
										if (!($subvalue == $scopeValue)) {
											unset($results[$key]);
										}
										break;
									
									case '<=':
										if (!($subvalue <= $scopeValue)) {
											unset($results[$key]);
										}
										break;
									
									case '>=':
										if (!($subvalue >= $scopeValue)) {
											unset($results[$key]);
										}
										break;
									
								}
							}
						}
					}
				}
			}
		}
		return $results;
	}

	public function revertTo(Model $model, $id, $conditions) {
		$Audit = ClassRegistry::init('Audit');
		$Audit->bindModel(array('hasMany' => array('AuditDelta')), false);
		$audit = $Audit->findById($id);
		if (!$audit || !$conditions) {
			return false;
		}
		$conditions = am($conditions, array(
			'created >=' => $audit['Audit']['created']
		));
		$audits = $Audit->find('all', array(
			'conditions' => $conditions,
			'order' => array(
				'created' => 'desc'
			),
			'contain' => false,
		));
		foreach ($audits as $item) {
			switch ($item['Audit']['event']) {
				case 'CREATE':
					$conditions = array(sprintf('%s.%s', $model->alias, $model->primaryKey) => $item['Audit']['entity_id']);
					if ($model->hasAny($conditions)) {
						$model->deleteAll($conditions, false, false);
					}
					break;

				case 'EDIT':
					$jsonObject = json_decode($item['Audit']['json_object']);
					$jsonObject = Set::reverse($jsonObject);	
					$data = array();
					foreach ($item['AuditDelta'] as $subitem) {
						$data[$subitem['property_name']] = $subitem['old_value'];
					}
					$model->id = $jsonObject[$model->alias][$model->primaryKey];
					if (!empty($data)) {
						$model->save($data, array('callbacks' => false));
					}
					break;

				case 'DELETE':
					$data = json_decode($item['Audit']['json_object']);
					$data = Set::reverse($data);	
					if (!empty($data)) {
						$model->save($data, array('callbacks' => false));
					}
					break;
				
			}
			$Audit->delete($item['Audit']['id']);
		}
		return true;
	}
}
