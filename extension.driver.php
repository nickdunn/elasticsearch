<?php
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch_logs.php');
		
	class Extension_Elasticsearch extends Extension {
				
		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/publish/new/',
					'delegate'	=> 'EntryPostCreate',
					'callback'	=> 'indexEntry'
				),				
				array(
					'page'		=> '/publish/edit/',
					'delegate'	=> 'EntryPostEdit',
					'callback'	=> 'indexEntry'
				),
				array(
					'page' => '/frontend/',
					'delegate' => 'EventPostSaveFilter',
					'callback' => 'indexEntry'
				),
				array(
					'page'		=> '/publish/',
					'delegate'	=> 'EntryPreDelete',
					'callback'	=> 'deleteEntry'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => 'savePreferences'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendPageResolved',
					'callback'	=> 'generate_session'
				)
			);
		}
		
		/**
		* Append navigation to Blueprints menu
		*/
		public function fetchNavigation() {
			return array(
				array(
					'name'		=> __('ElasticSearch'),
					'type'		=> 'structure',
					'children'	=> array(
						array(
							'name'		=> __('Mappings'),
							'link'		=> '/mappings/'
						),
						array(
							'name'		=> __('Session Logs'),
							'link'		=> '/sessions/'
						),
						array(
							'name'		=> __('Query Logs'),
							'link'		=> '/queries/'
						),
					)
				)
			);
		}
		
		public function install() {
			
			// create tables
			Symphony::Database()->query(
				"CREATE TABLE `tbl_elasticsearch_logs` (
				  `id` varchar(255) NOT NULL DEFAULT '',
				  `date` datetime NOT NULL,
				  `keywords` varchar(255) DEFAULT NULL,
				  `keywords_raw` varchar(255) DEFAULT NULL,
				  `sections` varchar(255) DEFAULT NULL,
				  `page` int(11) NOT NULL,
				  `results` int(11) DEFAULT NULL,
				  `session_id` varchar(255) DEFAULT NULL,
				  `user_agent` varchar(255) DEFAULT NULL,
				  `ip` varchar(255) DEFAULT NULL,
				  PRIMARY KEY (`id`),
				  UNIQUE KEY `id` (`id`),
				  KEY `keywords` (`keywords`),
				  KEY `date` (`date`),
				  KEY `session_id` (`session_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
			);
			
			// create config defaults
			Symphony::Configuration()->setArray(
				array('elasticsearch' => array(
					
					// default preferences
					'host' => '',
					'index-name' => '',
					
					// batch reindexing
					'reindex-batch-size' => 20,
					'reindex-batch-delay' => 0,
					
					// search results
					'per-page' => 20,
					'sort' => '_score',
					'direction' => 'desc',
					'highlight-fragment-size' => 200,
					'highlight-per-field' => 1,
					'build-entry-xml' => 'no',
					'default-sections' => '',
					'default-language' => '',
					
					// logging
					'log-searches' => 'yes',
					
				))
			);
			
			Administration::instance()->saveConfig();
			
			// create workspace structure
			$config = (object)Symphony::Configuration()->get('directory');
			General::realiseDirectory(WORKSPACE . '/elasticsearch', $config->{'write_mode'});
			General::writeFile(WORKSPACE . '/elasticsearch/.htaccess', file_get_contents(EXTENSIONS . '/elasticsearch/templates/.htaccess'), $config->{'write_mode'});
			General::writeFile(WORKSPACE . '/elasticsearch/index.json', file_get_contents(EXTENSIONS . '/elasticsearch/templates/index.json'), $config->{'write_mode'});
			General::realiseDirectory(WORKSPACE . '/elasticsearch/mappings', $config->{'write_mode'});
		}
		
		public function uninstall() {
			
			$config = (object)Symphony::Configuration()->get('elasticsearch');
			
			if($config->{'index-name'}) {
				// delete the ES index
				ElasticSearch::init(FALSE);
				$index = ElasticSearch::getClient()->getIndex($config->{'index-name'});
				if($index->exists()) $index->delete();
			}
			
			if($config) {
				// remove config
				Symphony::Configuration()->remove('elasticsearch');			
				Administration::instance()->saveConfig();
			}
			
			// remove table
			Symphony::Database()->query("DROP TABLE `tbl_elasticsearch_logs`");
			
			// remove workspace mappings
			// TODO: perhaps General needs a removeDirectory method?
		}
		
		public function generate_session($context) {
			$cookie_name = sprintf('%sselasticsearch-session', Symphony::Configuration()->set('cookie_prefix', 'symphony'));
			$cookie_value = $_COOKIE[$cookie_name];
			// cookie has not been set
			if(!isset($cookie_value)) {
				$cookie_value = uniqid() . time();
				setcookie($cookie_name, $cookie_value);
			}
			ElasticSearchLogs::setSessionIdFromCookie($cookie_value);
		}
		
		/**
		* Index this entry for search
		*
		* @param object $context
		*/
		public function indexEntry($context) {
			ElasticSearch::indexEntry(
				$context['entry'],
				$context['section']
			);
		}
		
		/**
		* Delete this entry's search index
		*
		* @param object $context
		*/
		public function deleteEntry($context) {
			if(!is_array($context['entry_id'])) $context['entry_id'] = is_array($context['entry_id']);
			foreach($context['entry_id'] as $entry_id) {
				ElasticSearch::deleteEntry($entry_id);
			}
		}
		
		public function appendPreferences($context) {

			$config = Symphony::Configuration()->get('elasticsearch');

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', 'ElasticSearch'));


			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Host'));
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][host]',
				$config['host'],
				'text',
				array(
					'placeholder' => 'e.g. http://localhost:9200/'
				)
			));
			$label->appendChild(new XMLElement('span', __('Include trailing slash.'), array('class'=>'help')));
			$group->appendChild($label);
			
			$label = Widget::Label(__('Index Name'));
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][index-name]',
				$config['index-name'],
				'text',
				array(
					'placeholder' => 'e.g. ' . Lang::createHandle(Symphony::Configuration()->get('sitename', 'general'))
				)
			));
			$label->appendChild(new XMLElement('span', __('Use handle format (no spaces).'), array('class'=>'help')));
			$group->appendChild($label);
			
			$fieldset->appendChild($group);
			
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Username <i>Optional</i>'));
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][username]',
				$config['username']
			));
			$group->appendChild($label);
			
			$label = Widget::Label(__('Password <i>Optional</i>'));
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][password]',
				$config['password']
			));
			$group->appendChild($label);
			
			
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][index_name_original]',
				$config['index-name'],
				'text',
				array(
					'type' => 'hidden'
				)
			));
			
			$fieldset->appendChild($group);
			
							
			$context['wrapper']->appendChild($fieldset);
			
		}
		
		public function savePreferences($context) {
			$settings = array_map('trim', $context['settings']['elasticsearch']);
			
			$index_to_delete = NULL;
			$index_to_create = NULL;
			
			// index name has changed, so delete the original and create new
			if($settings['index-name'] !== $settings['index_name_original']) {
				$index_to_delete = $settings['index_name_original'];
				$index_to_create = $settings['index-name'];
			}
			
			// only try to delete existing index if it exists (i.e. don't run this
			// the first time the extension is configured and there's no existing index)
			if(!empty($index_to_delete)) {
				
				// instantiate extension's ES helper class
				ElasticSearch::init(
					$settings['host'],
					$index_to_delete,
					$settings['username'],
					$settings['password']
				);
				
				// delete original index
				$index = ElasticSearch::getClient()->getIndex($index_to_delete);
				if($index->exists()) $index->delete();
				
				// reset
				ElasticSearch::flush();
				
			}
			
			if(!empty($index_to_create)) {
				
				// instantiate extension's ES helper class
				ElasticSearch::init(
					$settings['host'],
					$index_to_create,
					$settings['username'],
					$settings['password']
				);
				
				// create new index
				$index = ElasticSearch::getClient()->getIndex($index_to_create);
				
				$index_settings_file = WORKSPACE . '/elasticsearch/index.json';
				$index_settings = array();
				if(file_exists($index_settings_file)) {
					$index_settings = json_decode(file_get_contents($index_settings_file), TRUE);
				} else {
					$index_settings = array();
				}
				
				if(is_null($index_settings)) {
					throw new Exception('ElasticSearch: workspace/elasticsearch/index.json is not valid JSON');
				}
				
				$index->create($index_settings, TRUE);
			
			}
			
			unset($context['settings']['elasticsearch']['index_name_original']);
			
		}
		
		
		
	}
