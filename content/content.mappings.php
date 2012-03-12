<?php
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch_administrationpage.php');
	
	require_once(TOOLKIT . '/class.sectionmanager.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	class contentExtensionElasticSearchMappings extends ElasticSearch_AdministrationPage {
		
		private $reindex = array();
		
		public function __construct(){
			parent::__construct();
			ElasticSearch::init();
		}
		
		public function action() {
			$checked = @array_keys($_POST['items']);
			
			if (is_array($checked) and !empty($checked)) {
				switch ($_POST['with-selected']) {
					case 'rebuild':
						foreach ($checked as $handle) {
							ElasticSearch::getIndex()->getType($handle)->delete();
							ElasticSearch::createType($handle);
							redirect("{$this->uri}/mappings/");
						}
					break;
					case 'delete':
						foreach ($checked as $handle) {
							$type = ElasticSearch::getIndex()->getType($handle);
							$type->delete();
						}						
						redirect("{$this->uri}/mappings/");
					break;	
					case 'reindex':
						$this->reindex = $checked;
					break;
				}
			}
		}
		
		public function view() {
			parent::view(FALSE);
			
			if(isset($this->mode)) {
				$section = $this->mode;
				header('Content-Type: application/json');
				echo file_get_contents(WORKSPACE . '/elasticsearch/mappings/' . $section . '.json');
				die;
			}
			
			$this->addScriptToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.mappings.js', 101);
			$this->addStylesheetToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.mappings.css', 'screen', 102);
			
			$this->setPageType('table');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('ElasticSearch') . ' &ndash; ' . __('Mappings'));
						
			$types = ElasticSearch::getAllTypes();
			
			$tableHead = array();
			$tableBody = array();
			
			$tableHead[] = array(__('Section'), 'col');
			$tableHead[] = array(__('Mapped Fields'), 'col');
			$tableHead[] = array(__('Mapping JSON'), 'col');
			$tableHead[] = array(__('Entries'), 'col');
			
			if (!is_array($types) or empty($types)) {
				$tableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None Found.'), 'inactive', null, count($tableHead))))
				);
			}
			
			else {
				
				foreach ($types as $type) {
					
					$col_name = Widget::TableData($type->section->get('name'));
					$col_name->appendChild(Widget::Input('items['.$type->section->get('handle').']', NULL, 'checkbox'));
					
					$col_fields = Widget::TableData(implode(', ', $type->fields));
					$col_json = Widget::TableData(sprintf('<a href="%s">%s.json</a>', $type->section->get('handle'), $type->section->get('handle')));
					
					if($type->type) {
						
						$count = $type->type->count();
						
						$col_count = Widget::TableData(
							'<span id="reindex-' . $type->section->get('handle') . '">' . 
								(string)$count . ' ' . (($count == 1) ? 'entry' : 'entries') . 
							'</span>',
							$count_class . ' count-column'
						);
					} else {
						$col_count = Widget::TableData('Rebuild mapping before continuing', $count_class . ' count-column inactive');
					}
					
					$attributes = array(
						'data-handle' => $type->section->get('handle')
					);
					if(in_array($type->section->get('handle'), $this->reindex) && $type->type) {
						$attributes['data-reindex'] = 'yes';
					}
					
					$tableBody[] = Widget::TableRow(
						array($col_name, $col_fields, $col_json, $col_count),
						NULL, NULL, NULL,
						$attributes
					);
					
				}
			}
			
			$table = Widget::Table(
				Widget::TableHead($tableHead), null, 
				Widget::TableBody($tableBody),
				'selectable'
			);
			
			$this->Form->appendChild($table);
			
			$actions = new XMLElement('div');
			$actions->setAttribute('class', 'actions');
			
			$options = array(
				array(null, false, __('With Selected...')),
				array('reindex', false, __('Reindex Entries')),
				array('rebuild', false, __('Rebuild Mapping')),
				array('delete', false, __('Delete Mapping')),
			);
			
			$actions->appendChild(Widget::Select('with-selected', $options));
			$actions->appendChild(Widget::Input('action[apply]', __('Apply'), 'submit'));
			
			$this->Form->appendChild($actions);

		}
	
	}