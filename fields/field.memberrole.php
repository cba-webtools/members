<?php

	require_once(TOOLKIT . '/fields/field.select.php');

	Class fieldMemberRole extends fieldSelect {

	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/

		public function __construct(&$parent){
			parent::__construct($parent);
			$this->_name = __('Member: Role');
			$this->_showassociation = false;
		}

		public function canToggle(){
			return true;
		}

		public function allowDatasourceOutputGrouping(){
			return true;
		}

		public function mustBeUnique(){
			return true;
		}

	/*-------------------------------------------------------------------------
		Setup:
	-------------------------------------------------------------------------*/

		public static function createSettingsTable() {
			return Symphony::Database()->query("
				CREATE TABLE IF NOT EXISTS `tbl_fields_memberrole` (
				  `id` int(11) unsigned NOT NULL auto_increment,
				  `field_id` int(11) unsigned NOT NULL,
				  `default_role` int(11) unsigned NOT NULL,
				  PRIMARY KEY (`id`),
				  UNIQUE KEY `field_id` (`field_id`)
				) ENGINE=MyISAM;
			");
		}

		public function createTable(){
			return Symphony::Database()->query(
				"CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
				  `id` int(11) unsigned NOT NULL auto_increment,
				  `entry_id` int(11) unsigned NOT NULL,
 				  `role_id` int(11) unsigned NOT NULL,
				  PRIMARY KEY  (`id`),
				  UNIQUE KEY `entry_id` (`entry_id`)
				)"
			);
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		public function getToggleStates(){
			$roles = RoleManager::fetch();

			$states = array();
			if(is_array($roles) && !empty($roles)) foreach($roles as $r){
				$states[$r->get('id')] = $r->get('name');
			}

			return $states;
		}

		public function toggleFieldData($data, $newState){
			$data['role_id'] = $newState;
			return $data;
		}

	/*-------------------------------------------------------------------------
		Settings:
	-------------------------------------------------------------------------*/

		public function setFromPOST(Array $settings = array()) {
			$settings['required'] = 'yes';

			parent::setFromPOST($settings);
		}

		public function displaySettingsPanel(&$wrapper, $errors=NULL){
			Field::displaySettingsPanel($wrapper, $errors);

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			// Get Role in system
			$roles = RoleManager::fetch();
			$options = array();
			if(is_array($roles) && !empty($roles)) {
				foreach($roles as $role) {
					$options[] = array($role->get('id'), ($this->get('default_role') == $role->get('id')), $role->get('name'));
				}
			}

			$label = new XMlElement('label', __('Default Member Role'));
			$label->appendChild(Widget::Select(
				"fields[{$this->get('sortorder')}][default_role]", $options
			));

			$group->appendChild($label);
			$wrapper->appendChild($group);

			$this->appendShowColumnCheckbox($wrapper);
		}

		public function checkFields(&$errors, $checkForDuplicates=true) {
			Field::checkFields(&$errors, $checkForDuplicates);
		}

		public function commit(){
			if(!Field::commit()) return false;

			$id = $this->get('id');

			if($id === false) return false;

			fieldMemberRole::createSettingsTable();

			$fields = array(
				'field_id' => $id,
				'default_role' => $this->get('default_role')
			);

			Symphony::Configuration()->set('role', $id, 'members');
			Administration::instance()->saveConfig();

			Symphony::Database()->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '$id' LIMIT 1");
			return Symphony::Database()->insert($fields, 'tbl_fields_' . $this->handle());
		}

	/*-------------------------------------------------------------------------
		Publish:
	-------------------------------------------------------------------------*/

		public function displayPublishPanel(XMLElement &$wrapper, $data = null, $error = null, $prefix = null, $postfix = null, $entry_id = null) {
			$states = $this->getToggleStates();
			$options = array();

			if(is_null($entry_id)) {
				$data['role_id'] = $this->get('default_role');
			}

			foreach($states as $role_id => $role_name){
				$options[] = array(
					$role_id,
					$role_id == $data['role_id'],
					$role_name
				);
			}

			$label = Widget::Label($this->get('label'));
			$label->appendChild(Widget::Select(
				'fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, $options)
			);

			if(!is_null($error)) {
				$wrapper->appendChild(Widget::wrapFormElementWithError($label, $error));
			}
			else {
				$wrapper->appendChild($label);
			}
		}

		public function processRawFieldData($data, &$status, $simulate=false, $entry_id=NULL){
			$status = self::__OK__;
			return array('role_id' => $data);
		}

	/*-------------------------------------------------------------------------
		Output:
	-------------------------------------------------------------------------*/

		public function fetchIncludableElements() {
			return array(
				$this->get('element_name'),
				$this->get('element_name') . ': permissions'
			);
		}

		public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null) {
			if(!is_array($data) || empty($data)) return;

			$role_id = $data['role_id'];
			$role = RoleManager::fetch($role_id);

			$element = new XMLElement($this->get('element_name'), null, array(
				'id' => $role->get('id'),
				'mode' => ($mode == "permissions") ? $mode : 'normal'
			));
			$element->appendChild(
				new XMLElement('name', General::sanitize($role->get('name')), array(
					'handle' => $role->get('handle')
				))
			);

			if($mode == "permissions") {
				// The more information that's provided here, the easier it will be for
				// a developer to write XSLT that is dynamic and can work when the Roles
				// are updated in the backend. For instance you could check if a page was
				// denied access before creating a link to it. This check would then work
				// for all roles, instead of writing logic for Role A, Role B. Consider it
				// feature detection, rather than user agent detection.

				$forbidden_pages = $role->get('forbidden_pages');
				if(is_array($forbidden_pages) & !empty($forbidden_pages)) {
					$page_data = Symphony::Database()->fetch(sprintf(
						"SELECT * FROM `tbl_pages` WHERE id IN (%s)",
						implode(',', $forbidden_pages)
					));

					if(is_array($page_data) && !empty($page_data)) {
						$pages = new XMLElement('forbidden-pages');

						foreach($page_data as $key => $page) {
							$attributes = array(
								'id' => $page['id'],
								'handle' => $page['handle']
							);

							if(!is_null($page['path'])) {
								$attributes['parent-path'] = General::sanitize($page['path']);
							}

							$pages->appendChild(
								new XMLElement('page', $page['title'], $attributes)
							);
						}

						$element->appendChild($pages);
					}
				}

				$event_permissions = $role->get('event_permissions');
				if(is_array($event_permissions) & !empty($event_permissions)) {
					$events = new XMLElement('events');

					foreach($event_permissions as $event => $event_data) {
						$item = new XMLElement('event', null, array('handle' => $event));
						foreach($event_data as $action => $level) {
							$item->appendChild(
								new XMLElement('action', EventPermissions::$permissionMap[$level], array(
									'type' => $action,
									'handle' => Lang::createHandle(EventPermissions::$permissionMap[$level])
								))
							);
						}

						$events->appendChild($item);
					}

					$element->appendChild($events);
				}
			}

			$wrapper->appendChild($element);
		}

		public function prepareTableValue($data, XMLElement $link=NULL){
			$role_id = $data['role_id'];

			$role = RoleManager::fetch($role_id);

			return parent::prepareTableValue(array(
				'value' => $role instanceof Role ? General::sanitize($role->get('name')) : null
			), $link);
		}

	/*-------------------------------------------------------------------------
		Filtering:
	-------------------------------------------------------------------------*/

		public function displayDatasourceFilterPanel(&$wrapper, $data=NULL, $errors=NULL, $fieldnamePrefix=NULL, $fieldnamePostfix=NULL){

			parent::displayDatasourceFilterPanel($wrapper, $data, $errors, $fieldnamePrefix, $fieldnamePostfix);

			$data = preg_split('/,\s*/i', $data);
			$data = array_map('trim', $data);

			$existing_options = $this->getToggleStates();

			if(is_array($existing_options) && !empty($existing_options)) {
				$optionlist = new XMLElement('ul');
				$optionlist->setAttribute('class', 'tags');

				foreach($existing_options as $option) {
					$optionlist->appendChild(new XMLElement('li', $option));
				}

				$wrapper->appendChild($optionlist);
			}

		}

		public function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation=false){

			$field_id = $this->get('id');

			if($andOperation) {
				foreach($data as $key => $bit){
					$joins .= " LEFT JOIN `tbl_entries_data_$field_id` AS `t$field_id$key` ON (`e`.`id` = `t$field_id$key`.entry_id) ";
					$joins .= " LEFT JOIN `tbl_members_roles` AS `tg$field_id$key` ON (`t$field_id$key`.`role_id` = `tg$field_id$key`.id) ";
					$where .= " AND (`t$field_id$key`.role_id = '$bit' OR (`tg$field_id$key`.name = '$bit' OR `tg$field_id$key`.handle = '$bit')) ";
				}
			}
			else {
				$data = !is_array($data) ? array($data) : $data;
				$value = implode("', '", $data);

				$joins .= " LEFT JOIN `tbl_entries_data_$field_id` AS `t$field_id` ON (`e`.`id` = `t$field_id`.entry_id) ";
				$joins .= " LEFT JOIN `tbl_members_roles` AS `tg$field_id` ON (`t$field_id`.`role_id` = `tg$field_id`.id) ";
				$where .= " AND (
								`t$field_id`.role_id IN ('$value')
								OR (
									`tg$field_id`.name IN ('$value')
									OR
									`tg$field_id`.handle IN ('$value')
								)
							) ";
			}

			return true;
		}

	/*-------------------------------------------------------------------------
		Sorting:
	-------------------------------------------------------------------------*/

		public function buildSortingSQL(&$joins, &$where, &$sort, $order='ASC') {
			$joins .= "INNER JOIN `tbl_entries_data_".$this->get('id')."` AS `ed` ON (`e`.`id` = `ed`.`entry_id`) ";
			$sort .= (strtolower($order) == 'random' ? 'RAND()' : "`ed`.`role_id` $order");
		}

	/*-------------------------------------------------------------------------
		Grouping:
	-------------------------------------------------------------------------*/

		public function groupRecords($records){

			if(!is_array($records) || empty($records)) return;

			$groups = array($this->get('element_name') => array());

			foreach($records as $r){
				$data = $r->getData($this->get('id'));

				$role_id = $data['role_id'];
				if(!$role = RoleManager::fetch($role_id)) continue;

				if(!isset($groups[$this->get('element_name')][$role_id])){
					$groups[$this->get('element_name')][$role_id] = array(
						'attr' => array(
							'id' => $role_id,
							'handle' => $role->get('handle'),
							'name' => General::sanitize($role->get('name'))
						),
						'records' => array(), 'groups' => array()
					);
				}

				$groups[$this->get('element_name')][$role_id]['records'][] = $r;

			}

			return $groups;
		}

	}

