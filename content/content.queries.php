<?php
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch_administrationpage.php');
	
	class contentExtensionElasticSearchQueries extends ElasticSearch_AdministrationPage {
		
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
			
			if (!isset($sort->column)) $sort->column = 'count';
			if (!isset($sort->direction)) $sort->direction = 'desc';
			
			if (!isset($filter->keywords) || empty($filter->keywords)) $filter->keywords = NULL;
			if (!isset($filter->date_from) || empty($filter->date_from)) $filter->date_from = date('Y-m-d', strtotime('last month'));
			if (!isset($filter->date_to) || empty($filter->date_to)) $filter->date_to = date('Y-m-d', strtotime('today'));
			if (!isset($filter->average_results['value']) || !is_numeric($filter->average_results['value'])) $filter->average_results = NULL;			
			if (!isset($filter->average_depth['value']) || !is_numeric($filter->average_depth['value'])) $filter->average_depth = NULL;
			
			if(is_array($filter->average_results)) $filter->average_results = implode('', $filter->average_results);
			if(is_array($filter->average_depth)) $filter->average_depth = implode('', $filter->average_depth);
			
			$output_mode = $_GET['output'];
			if (!isset($output_mode)) $output_mode = 'table';
			
		// Build pagination and fetch rows
		/*-----------------------------------------------------------------------*/
		
			$pagination->{'per-page'} = 50;
			$pagination->{'current-page'} = (@(int)$pagination->{'current-page'} > 1 ? (int)$pagination->{'current-page'} : 1);
			
			// get the logs!
			$rows = ElasticSearchLogs::getQueries(
				$sort->column, $sort->direction,
				$pagination->{'current-page'}, $pagination->{'per-page'},
				$filter
			);
			
			// total number of unique query terms
			$query_stats = ElasticSearchLogs::getTotalQueries($filter);
			//var_dump($query_stats);die;
			$pagination->{'total-entries'} = $query_stats->total;
			
			$pagination->start = max(1, (($pagination->{'current-page'} - 1) * $pagination->{'per-page'}));
			$pagination->end = ($pagination->start == 1 ? $pagination->{'per-page'} : $pagination->start + count($rows));
			$pagination->{'total-pages'} = ceil($pagination->{'total-entries'} / $pagination->{'per-page'});
			
			// sum of the "count" column for all queries i.e. total number of searches
			$total_search_count = ElasticSearchLogs::getSearchCount($filter);

			// cache amended filters for use elsewhere
			$this->sort = $sort;
			$this->filter = $filter;
			$this->pagination = $pagination;
						
			$filters_drawer = new ElasticSearch_Drawer('Filters', $this->__buildDrawerHTML($filter));
			
		// Set up page meta data
		/*-----------------------------------------------------------------------*/	
			
			$this->setPageType('table');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('ElasticSearch') . ' &ndash; ' . __('Query Logs'));
			$this->appendSubheading(__('Query Logs'), Widget::Anchor(
				__('Export CSV'), $this->__buildURL(NULL, array('output' => 'csv')), NULL, 'button'
			));
			
			$this->Context->appendChild($filters_drawer->drawer);
			
			
		// Build summary
		/*-----------------------------------------------------------------------*/
		
			$stats = new XMLElement('ul');
			$stats->appendChild(new XMLElement('li',
				__("<span>%s</span> unique queries from <span>%s</span> sessions.", array(number_format($query_stats->total), number_format($total_search_count)))
			));
			$stats->appendChild(new XMLElement('li',
				__("Average <span>%s</span> characters per query.", array((int)$query_stats->average_length))
			));
			$stats->appendChild(new XMLElement('li',
				__("Average <span>%s</span> results retrieved per search.", array(number_format($query_stats->average_results, 1)))
			));
			$stats->appendChild(new XMLElement('li',
				__("Average search depth <span>%s</span> pages.", array(number_format($query_stats->average_depth,1)))
			));
		
			$summary = new XMLElement('div', NULL, array('class' => 'summary'));
			$summary->appendChild($stats);
			$this->Form->appendChild($summary);
			
			
		// Build table
		/*-----------------------------------------------------------------------*/
								
			$tableHead = array();
			$tableBody = array();
			
			// append table headings
			$tableHead[] = array(__('Rank'), 'col');
			$tableHead[] = $this->__buildColumnHeader(__('Query'), 'keywords', 'asc');
			$tableHead[] = $this->__buildColumnHeader(__('Query (Raw)'), 'keywords', 'asc');
			$tableHead[] = $this->__buildColumnHeader(__('Frequency'), 'count', 'desc');
			$tableHead[] = array(__('%'), 'col');
			$tableHead[] = array(__('Cumulative %'), 'col');
			$tableHead[] = $this->__buildColumnHeader(__('Avg. results'), 'average_results', 'desc');
			$tableHead[] = $this->__buildColumnHeader(__('Avg. depth'), 'average_depth', 'desc');
			
			// no rows
			if (!is_array($rows) or empty($rows)) {
				$tableBody = array(
					Widget::TableRow(array(
						Widget::TableData(__('None Found.'), 'inactive', NULL, count($tableHead))
					))
				);
			}
			// we have rows
			else {
				
				// if not on the first page, the cululative percent column needs to start from the
				// column total of the previous page. Calling this method queries a dataset the size
				// of all previous pages, sums and returns the totals from all
				if($pagination->{'current-page'} > 1) {
					$cumulative_total = ElasticSearchLogs::getCumulativeSearchCount(
						$sort->column, $sort->direction,
						$pagination->{'current-page'}, $pagination->{'per-page'},
						$filter
					);
				}
				
				// rank starts from 1 on first page
				$rank = ($pagination->start == 1) ? $pagination->start : $pagination->start + 1;
				// initial percentage to start from (cumulative)
				$cumulative_percent = ($cumulative_total / $total_search_count) * 100;
				
				foreach ($rows as $row) {
					
					$row_percent = ($row['count'] / $total_search_count) * 100;
					$cumulative_percent += $row_percent;
					
					$r = array();
					$r[] = Widget::TableData($rank, 'rank');
					$r[] = Widget::TableData(
						(empty($row['keywords']) ? __('None') : stripslashes($row['keywords'])),
						(empty($row['keywords']) ? 'inactive query' : 'query')
					);
					$r[] = Widget::TableData(
						(empty($row['keywords']) ? __('None') : htmlentities(stripslashes($row['keywords_raw']))),
						'inactive query'
					);
					$r[] = Widget::TableData($row['count'], 'count');
					$r[] = Widget::TableData((number_format($row_percent, 2)) . '%', 'percent');
					$r[] = Widget::TableData((number_format($cumulative_percent, 2)) . '%', 'percent');
					$r[] = Widget::TableData(number_format($row['average_results'], 1), 'average-results');
					$r[] = Widget::TableData(number_format($row['average_depth'], 1), 'average-depth');
					
					$tableBody[] = Widget::TableRow($r);
					
					$rank++;
					
				}
				
			}
			
			if($output_mode == 'csv') {
				
				$file_path = sprintf('%s/search-index.query-log.%d.csv', TMP, time());
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
				
				fputcsv($csv, $columns, ',', '"');

				foreach($tableBody as $tr) {
					$cells = $tr->getChildren();
					$data = array();
					foreach($cells as $td) {
						$data[] = $td->getValue();
					}
					fputcsv($csv, $data, ',', '"');
				}
				
				fclose($csv);
				
				header('Content-type: application/csv');
				header('Content-Disposition: attachment; filename="' . end(explode('/', $file_path)) . '"');
				readfile($file_path);
				unlink($file_path);
				
				exit;
				
			}
			
			// append the table
			$table = Widget::Table(Widget::TableHead($tableHead), NULL, Widget::TableBody($tableBody));
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
			
			$label = new XMLElement('div', __('Query returned an average of'), array('class' => 'label performance'));
			$span = new XMLElement('span');
			$span->appendChild(Widget::Select('filter[average_results][compare]', array(
				array('=', preg_match('/^\=/', $filter->average_results), 'exactly'),
				array('<', preg_match('/^\</', $filter->average_results), 'less than'),
				array('>', preg_match('/^\>/', $filter->average_results), 'more than')
			)));
			$span->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'name' => 'filter[average_results][value]',
				'value' => trim($filter->average_results, '=<>'),
				'autocomplete' => 'off',
				'placeholder' => __('all'),
			)));
			
			$span->appendChild(new XMLElement('span', ' ' . __('result(s)')));
			$label->appendChild($span);
			$form->appendChild($label);
			
			$label = new XMLElement('div', __('Users visited depth of'), array('class' => 'label performance'));
			$span = new XMLElement('span');
			$span->appendChild(Widget::Select('filter[average_depth][compare]', array(
				array('=', preg_match('/^\=/', $filter->average_depth), 'exactly'),
				array('<', preg_match('/^\</', $filter->average_depth), 'less than'),
				array('>', preg_match('/^\>/', $filter->average_depth), 'more than')
			)));
			$span->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'name' => 'filter[average_depth][value]',
				'value' => trim($filter->average_depth, '=<>'),
				'autocomplete' => 'off',
				'placeholder' => __('all')
			)));
			$span->appendChild(new XMLElement('span', ' ' . __('page(s)')));
			$label->appendChild($span);
			$form->appendChild($label);
			
			$form->appendChild(new XMLElement('input', NULL, array('type' => 'submit', 'value' => __('Apply Filters'), 'class' => 'button create')));
			$form->appendChild(new XMLElement('input', NULL, array('type' => 'button', 'value' => __('Clear'), 'class' => 'button clear')));
			
			return $form->generate();
		}
		
	}