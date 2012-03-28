<?php
	
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');
	
	class ElasticSearch_AdministrationPage extends AdministrationPage {
		
		protected $filter = NULL;
		protected $sort = NULL;
		protected $pagination = NULL;
		
		protected $uri = NULL;
		protected $id = NULL;
		protected $mode = NULL;
		
		public function __construct(){
			$this->uri = URL . '/symphony/extension/elasticsearch';
			parent::__construct();
		}
		
		public function build($context) {
			$this->mode = $context[0];
			$this->id = $context[1];
			parent::build($context);
		}
		
		public function view($call_parent=TRUE) {
			$this->addElementToHead(new XMLElement(
				'script',
				"Symphony.Context.add('elasticsearch', " . json_encode(Symphony::Configuration()->get('elasticsearch')) . ")",
				array('type' => 'text/javascript')
			), 99);
			if($call_parent) parent::view();
		}
		
		protected function __buildPagination($pagination) {
			
			$ul = new XMLElement('ul');
			$ul->setAttribute('class', 'page');
			
			// first
			$li = new XMLElement('li');
			if ($pagination->{'current-page'} > 1) {
				$li->appendChild(
					Widget::Anchor(
						__('First'),
						$this->__buildURL(array('pagination->current-page' => 1))
					)
				);
			} else {
				$li->setValue(__('First'));
			}
			$ul->appendChild($li);
			
			// previous
			$li = new XMLElement('li');
			if ($pagination->{'current-page'} > 1) {
				$li->appendChild(
					Widget::Anchor(
						__('&larr; Previous'),
						$this->__buildURL(array('pagination->current-page' => $pagination->{'current-page'} - 1))
					)
				);
			} else {
				$li->setValue('&larr; ' . __('Previous'));
			}				
			$ul->appendChild($li);

			// summary
			$li = new XMLElement('li', __('Page %1$s of %2$s', array($pagination->{'current-page'}, max($pagination->{'current-page'}, $pagination->{'total-pages'}))));
			$li->setAttribute('title', __('Viewing %1$s - %2$s of %3$s entries', array(
				$pagination->start,
				$pagination->end,
				$pagination->{'total-entries'}
			)));
			$ul->appendChild($li);

			// next
			$li = new XMLElement('li');				
			if ($pagination->{'current-page'} < $pagination->{'total-pages'}) {
				$li->appendChild(
					Widget::Anchor(
						__('Next &rarr;'),
						$this->__buildURL(array('pagination->current-page' => ($pagination->{'current-page'} + 1)))
					)
				);
			} else {
				$li->setValue(__('Next') . ' &rarr;');
			}				
			$ul->appendChild($li);

			// last
			$li = new XMLElement('li');
			if ($pagination->{'current-page'} < $pagination->{'total-pages'}) {
				$li->appendChild(
					Widget::Anchor(
						__('Last'),
						$this->__buildURL(array('pagination->current-page' => $pagination->{'total-pages'}))
					)
				);
			} else {
				$li->setValue(__('Last'));
			}				
			$ul->appendChild($li);
			
			return $ul;
		}
		
		protected function __buildColumnHeader($label='', $column_name, $default_direction) {
			if($default_direction == 'asc') {
				$direction = 'asc';
				$direction_reverse = 'desc';
			} else {
				$direction = 'desc';
				$direction_reverse = 'asc';
			}
			return array(
				Widget::Anchor(
					$label,
					$this->__buildURL(
						array(
							'pagination->current-page' => 1,
							'sort->column' => $column_name,
							'sort->direction' => (($this->sort->column == $column_name && $this->sort->direction == $direction) ? $direction_reverse : $direction))
					),
					'',
					($this->sort->column == $column_name ? 'active' : '')
				),
				'col',
				array(
					'class' => $column_name
				)
			);
		}
		
		protected function __buildURL($override=array(), $extra=array()) {
	
			$sort = (object)$_GET['sort'];
			$filter = (object)$_GET['filter'];
			$pagination = (object)$_GET['pagination'];
			
			if(!is_array($override)) $override = array();			
			foreach($override as $context => $value) {
				$group = reset(explode('->', $context));
				$key = end(explode('->', $context));
				switch($group) {
					case 'sort': $sort->{$key} = $value; break;
					case 'filter': $filter->{$key} = $value; break;
					case 'pagination': $pagination->{$key} = $value; break;
				}
			}
			
			$parameters = array(
				'sort' => $sort,
				'filter' => $filter,
				'pagination' => $pagination
			);
			
			foreach($parameters as $name => $group) {
				foreach($group as $key => $value) {
					if(empty($value) || ($name == 'pagination' && $key != 'current-page')) continue;
					if(is_array($value)) {
						foreach($value as $k => $v) {
							$url .= sprintf('%s[%s][%s]=%s&amp;', (string)$name, (string)$key, (string)$k, (string)$v);
						}
					} else {
						$url .= sprintf('%s[%s]=%s&amp;', (string)$name, (string)$key, (string)$value);
					}
				}
			}
			
			foreach($extra as $key => $value) {
				$url .= sprintf('%s=%s&amp;', (string)$key, (string)$value);
			}
			
			$url = preg_replace("/&amp;$/", '', $url);
			return Administration::instance()->getCurrentPageURL() . '?' . $url;
		}
		
	}