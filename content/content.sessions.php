<?php
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch_administrationpage.php');
	require_once(EXTENSIONS . '/elasticsearch/lib/phpbrowscap/browscap/Browscap.php');

	class contentExtensionElasticSearchSessions extends ElasticSearch_AdministrationPage {
		
		public function build($context) {
			parent::build($context);
			if (isset($_POST['filter']['keyword']) != '') {
				redirect(Administration::instance()->getCurrentPageURL() . '?keywords=' . $_POST['keywords']);
			}
		}
						
		public function view() {
			
			$this->addStylesheetToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.stats.css', 'screen', 102);
			
			$this->addStylesheetToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.drawer.css', 'screen', 103);
			$this->addScriptToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.drawer.js', 103);
			
			$this->addScriptToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.jquery.ui.js', 104);
			$this->addStylesheetToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.jquery.daterangepicker.css', 'screen', 105);
			$this->addScriptToHead(URL . '/extensions/elasticsearch/assets/elasticsearch.jquery.daterangepicker.js', 106);
			
			parent::view(FALSE);
			
			// Get URL parameters, set defaults
			/*-----------------------------------------------------------------------*/	
			$sort = (object)$_GET['sort'];
			$filter = (object)$_GET['filter'];
			$pagination = (object)$_GET['pagination'];
			
			if (!isset($sort->column)) $sort->column = 'date';
			if (!isset($sort->direction)) $sort->direction = 'desc';
			
			if (!isset($filter->keywords) || empty($filter->keywords)) $filter->keywords = NULL;
			if (!isset($filter->date_from) || empty($filter->date_from)) $filter->date_from = date('Y-m-d', strtotime('last month'));
			if (!isset($filter->date_to) || empty($filter->date_to)) $filter->date_to = date('Y-m-d', strtotime('today'));
			if (!isset($filter->results['value']) || !is_numeric($filter->results['value'])) $filter->results = NULL;
			if (!isset($filter->depth['value']) || !is_numeric($filter->depth['value'])) $filter->depth = NULL;
			if (!isset($filter->session_id) || empty($filter->session_id)) $filter->session_id = NULL;
			if (!isset($filter->user_agent) || empty($filter->user_agent)) $filter->user_agent = NULL;
			if (!isset($filter->ip) || empty($filter->ip)) $filter->ip = NULL;
			
			if(is_array($filter->results)) $filter->results = implode('', $filter->results);
			if(is_array($filter->depth)) $filter->depth = implode('', $filter->depth);
						
			$output_mode = $_GET['output'];
			if (!isset($output_mode)) $output_mode = 'table';
			
			// Build pagination and fetch rows
			/*-----------------------------------------------------------------------*/
			$pagination->{'per-page'} = (int)Symphony::Configuration()->get('pagination_maximum_rows', 'symphony');
			$pagination->{'current-page'} = (@(int)$pagination->{'current-page'} > 1 ? (int)$pagination->{'current-page'} : 1);
			
			// get the logs!
			$rows = ElasticSearchLogs::getSessions(
				$sort->column, $sort->direction,
				$pagination->{'current-page'}, $pagination->{'per-page'},
				$filter
			);
			
			// total number of unique query terms
			$pagination->{'total-entries'} = ElasticSearchLogs::getTotalSessions($filter);
			
			$pagination->start = max(1, (($pagination->{'current-page'} - 1) * $pagination->{'per-page'}));
			$pagination->end = ($pagination->start == 1 ? $pagination->{'per-page'} : $pagination->start + count($rows));
			$pagination->{'total-pages'} = ceil($pagination->{'total-entries'} / $pagination->{'per-page'});

			// cache amended filters for use elsewhere
			$this->sort = $sort;
			$this->filter = $filter;
			$this->pagination = $pagination;
			
			// Set up page meta data
			/*-----------------------------------------------------------------------*/	
			
			$this->setPageType('table');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('ElasticSearch') . ' &ndash; ' . __('Session Logs'));
			
			$this->insertDrawer(Widget::Drawer('elasticsearch', __('Filter Sessions'), $this->__buildDrawerHTML($filter), 'opened'), 'horizontal');
			
			$this->appendSubheading(__('Session Logs'), Widget::Anchor(
				__('Export CSV'), $this->__buildURL(NULL, array('output' => 'csv')), NULL, 'button'
			));
			
			$this->Context->appendChild($filters_drawer->drawer);
			
			$tableHead = array();
			$tableBody = array();
			
			// append table headings
			$tableHead[] = $this->__buildColumnHeader(__('Date'), 'date', 'desc');
			$tableHead[] = array(__('Query'), 'keywords');
			$tableHead[] = $this->__buildColumnHeader(__('Results'), 'results', 'desc');
			$tableHead[] = $this->__buildColumnHeader(__('Depth'), 'depth', 'desc');
			$tableHead[] = array(__('Session ID'));
			$tableHead[] = array(__('IP Address'));
			$tableHead[] = array(__('Browser'));
			
			if (!is_array($rows) or empty($rows)) {
				$tableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None Found.'), 'inactive', null, count($tableHead))))
				);
			}
			
			else {
				
				$browscap = new Browscap(CACHE);
				
				$alt = FALSE;
				foreach ($rows as $row) {
					
					if(!empty($row['user_agent'])) {
						$browser = $browscap->getBrowser($row['user_agent']);
						$browser_string = sprintf('%s %s (%s)', $browser->Browser, $browser->MajorVer, $browser->Platform);
					} else {
						$browser_string = '';
					}
					
					$searches = ElasticSearchLogs::getSessionSearches($row['session_id']);
					
					foreach($searches as $i => $search) {
						
						$r = array();
						//$r[] = Widget::TableData('', NULL, NULL, 3);
						$r[] = Widget::TableData(
							DateTimeObj::get(
								__SYM_DATETIME_FORMAT__,
								strtotime($search['date'])
							),
							'date'
						);

						$keywords = $search['keywords'];
						$keywords_class = '';
						if ($keywords == '') {
							$keywords = __('None');
							$keywords_class = 'inactive';
						}
						
						$r[] = Widget::TableData(stripslashes($keywords), $keywords_class . ' keywords');
						$r[] = Widget::TableData($search['results'], 'results');
						$r[] = Widget::TableData($search['page'], 'depth');
						
						if($i == 0) {
							$r[] = Widget::TableData($row['session_id'], 'inactive');
							$r[] = Widget::TableData(empty($row['ip']) ? __('None') : $row['ip'], 'inactive');
							$r[] = Widget::TableData(empty($browser_string) ? __('None') : '<span title="'.$row['user_agent'].'">' . $browser_string . '</span>', 'inactive');
						} else {
							$r[] = Widget::TableData('', NULL, NULL, 3);
						}

						$tableBody[] = Widget::TableRow($r, 'search ' . ($alt ? 'alt' : '') . ($i == (count($searches) - 1) ? ' last' : ''));
						
					}
					
					$alt = !$alt;
					
				}
			}
			
			if($output_mode == 'csv') {
				
				$file_path = sprintf('%s/search-index.session-log.%d.csv', TMP, time());
				$csv = fopen($file_path, 'w');
				
				$columns = array();
				foreach($tableHead as $i => $heading) {
					$element = reset($heading);
					if($element instanceOf XMLElement) {
						$columns[] = reset($heading)->getValue();
					} else {
						$columns[] = (string)$element;
					}
				}
				$columns[] = 'Session ID';
				$columns[] = 'User Agent';
				$columns[] = 'IP';
				
				fputcsv($csv, $columns, ',', '"');

				$meta = array();

				foreach($tableBody as $tr) {
					$cells = $tr->getChildren();
					if(preg_match("/session-meta/", $tr->getAttribute('class'))) {
						$meta = array();
						foreach($cells as $i => $td) {
							switch($i) {
								case 0: $meta['session_id'] = $td->getValue(); break;
								case 1: $meta['user_agent'] = $td->getValue(); break;
								case 2: $meta['ip'] = $td->getValue(); break;
							}
						}
					} else {
						$data = array();
						foreach($cells as $td) {
							$data[] = $td->getValue();
						}
						$data[] = $meta['session_id'];
						$data[] = $meta['user_agent'];
						$data[] = $meta['ip'];
						fputcsv($csv, $data, ',', '"');
					}
					
				}
				
				fclose($csv);
				
				header('Content-type: application/csv');
				header('Content-Disposition: attachment; filename="' . end(explode('/', $file_path)) . '"');
				readfile($file_path);
				unlink($file_path);
				
				exit;
				
			}
			
			$table = Widget::Table(Widget::TableHead($tableHead), NULL, Widget::TableBody($tableBody), 'sessions');
			$this->Form->appendChild($table);
			
			$this->Form->appendChild(new XMLElement('div', NULL, array('class' => 'actions')));
			
			// build pagination
			if ($pagination->{'total-pages'} > 1) {
				$this->Form->appendChild($this->__buildPagination($pagination));
			}

		}
		
		private function __buildDrawerHTML($filter) {
			
			$form = new XMLElement('form', NULL, array('action' => '', 'method' => 'get'));
			
			$range = ElasticSearchLogs::getDateRange();
			
			$label = new XMLElement('div', NULL, array(
				'data-dateMin' => date('Y-m-d', strtotime($range->min)),
				'data-dateMax' => date('Y-m-d', strtotime($range->max)),
				'class' => 'label date-range'
			));
			$label->appendChild(new XMLElement('span', _('Date range')));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('From'),
				'name' => 'filter[date_from]',
				'value' => $filter->date_from,
				'autocomplete' => 'off'
			)));
			$label->appendChild(new XMLElement('span', __('to'), array('class' => 'conjunctive')));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('To'),
				'name' => 'filter[date_to]',
				'value' => $filter->date_to,
				'autocomplete' => 'off'
			)));
			$form->appendChild($label);
			
			// generate a random noun
			$password = General::generatePassword();
			$password = preg_replace('/[0-9]/', '', $password); // remove numbers
			preg_match('/([A-Z][a-z]+){1,}/', $password, $nouns); // split into separate words based on capitals
			$noun = strtolower(end($nouns));
			
			$label = new XMLElement('label', '<span>'.__('Query').'</span>', array('class' => 'keywords'));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('e.g. %s', array($noun)),
				'name' => 'filter[keywords]',
				'value' => $filter->keywords
			)));
			$form->appendChild($label);
			
			$label = new XMLElement('div', __('Query returned'), array('class' => 'label performance'));
			$span = new XMLElement('span');
			$span->appendChild(Widget::Select('filter[results][compare]', array(
				array('=', preg_match('/^\=/', $filter->results), 'exactly'),
				array('<', preg_match('/^\</', $filter->results), 'less than'),
				array('>', preg_match('/^\>/', $filter->results), 'more than')
			)));
			$span->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'name' => 'filter[results][value]',
				'value' => trim($filter->results, '=<>'),
				'autocomplete' => 'off',
				'placeholder' => __('all'),
			)));
			
			$span->appendChild(new XMLElement('span', ' ' . __('result(s)')));
			$label->appendChild($span);
			$form->appendChild($label);
			
			$label = new XMLElement('div', __('User visited depth of'), array('class' => 'label performance'));
			$span = new XMLElement('span');
			$span->appendChild(Widget::Select('filter[depth][compare]', array(
				array('=', preg_match('/^\=/', $filter->depth), 'exactly'),
				array('<', preg_match('/^\</', $filter->depth), 'less than'),
				array('>', preg_match('/^\>/', $filter->depth), 'more than')
			)));
			$span->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'name' => 'filter[depth][value]',
				'value' => trim($filter->depth, '=<>'),
				'autocomplete' => 'off',
				'placeholder' => __('all'),
			)));
			$span->appendChild(new XMLElement('span', ' ' . __('page(s)')));
			$label->appendChild($span);
			$form->appendChild($label);
			
			$label = new XMLElement('div', '<span>'.__('User').'</span>', array('class' => 'label triple-input'));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('Session ID'),
				'name' => 'filter[session_id]',
				'value' => $filter->session_id
			)));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('IP Address'),
				'name' => 'filter[ip]',
				'value' => $filter->ip
			)));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('Browser'),
				'name' => 'filter[user_agent]',
				'value' => $filter->user_agent
			)));
			$form->appendChild($label);
			
			$form->appendChild(new XMLElement('input', NULL, array('type' => 'submit', 'value' => __('Apply Filters'), 'class' => 'button create')));
			$form->appendChild(new XMLElement('input', NULL, array('type' => 'button', 'value' => __('Clear'), 'class' => 'button clear')));
			
			return $form;
		}
				
	}