<?php
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch_logs.php');
		
	class Extension_Elasticsearch extends Extension {
		
		public function about() {
			return array(
				'name'			=> 'ElasticSearch',
				'version'		=> '0.2',
				'release-date'	=> '',
				'author'		=> array(
					'name'			=> 'Nick Dunn'
				),
				'description' => 'Integrate ElasticSearch into your site.'
			);
		}
		
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
					'delegate'	=> 'Delete',
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
					'location'	=> __('ElasticSearch'),
					'name'		=> __('Mappings'),
					'link'		=> '/mappings/'
				),
				array(
					'location'	=> __('ElasticSearch'),
					'name'		=> __('Session Logs'),
					'link'		=> '/sessions/'
				),
				array(
					'location'	=> __('ElasticSearch'),
					'name'		=> __('Query Logs'),
					'link'		=> '/queries/'
				),
			);
		}
		
		public function install() {
			
			// create tables
			Symphony::Database()->query(
				"CREATE TABLE `tbl_elasticsearch_logs` (
				  `id` varchar(255) NOT NULL DEFAULT '',
				  `date` datetime NOT NULL,
				  `keywords` varchar(255) DEFAULT NULL,
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
			
			// delete the ES index
			ElasticSearch::init(FALSE);
			$index = ElasticSearch::getClient()->getIndex($config['index-name']);
			if($index->exists()) $index->delete();
			
			// remove config
			Symphony::Configuration()->remove('elasticsearch');			
			Administration::instance()->saveConfig();
			
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

			$config = Symphony::Engine()->Configuration()->get('elasticsearch');

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', 'ElasticSearch'));


			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Host'));
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][host]',
				$config['host'],
				null,
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
				null,
				array(
					'placeholder' => 'e.g. ' . Lang::createHandle(Symphony::Engine()->Configuration->get('sitename', 'general'))
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
				$config['username'],
				null
			));
			$group->appendChild($label);
			
			$label = Widget::Label(__('Password <i>Optional</i>'));
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][password]',
				$config['password'],
				null
			));
			$group->appendChild($label);
			
			
			$label->appendChild(Widget::Input(
				'settings[elasticsearch][index_name_original]',
				$config['index-name'],
				null,
				array(
					'type' => 'hidden'
				)
			));
			
			$fieldset->appendChild($group);
			
							
			$context['wrapper']->appendChild($fieldset);
			
		}
		
		public function savePreferences($context) {
			$settings = array_map('trim', $context['settings']['elasticsearch']);
			
			// index name has changed, so delete the original and create new
			if(!empty($settings['index_name_original']) && ($settings['index-name'] !== $settings['index_name_original'])) {
				
				// instantiate extension's ES helper class, do not auto create the index
				ElasticSearch::init(FALSE);
				
				// delete original index
				$index = ElasticSearch::getClient()->getIndex($settings['index_name_original']);
				if($index->exists()) $index->delete();
				
				// create new index
				$index = ElasticSearch::getClient()->getIndex($settings['index-name']);
				
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
